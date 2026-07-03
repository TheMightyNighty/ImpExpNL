<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "imp_exp_nl".
 *
 * (c) 2026 Robert Schleiermacher
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Robbi\ImpExpNL\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Domain\ConflictStrategy;
use Robbi\ImpExpNL\Domain\ExportManifest;
use Robbi\ImpExpNL\Domain\SystemFields;
use Robbi\ImpExpNL\Domain\UidMap;
use Robbi\ImpExpNL\Event\ModifyImportDataEvent;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImportService
{
    /** DataHandler-Platzhalter-Präfixe für neu anzulegende Records. */
    private const NEW_PAGE_PREFIX = 'NEW_PAGE_';
    private const NEW_CONTENT_PREFIX = 'NEW_CONTENT_';

    private UidMap $uidMap;

    /** Nur tatsächlich neu angelegte Records – Basis für den Rollback. */
    private UidMap $createdMap;

    /** @var array<string, array<int,int>> Rollback-Basis (erstellte Records inkl. Registry). */
    private array $rollbackMap = [];

    /** Anzahl der vom DataHandler gemeldeten Fehler. */
    private int $dataHandlerErrors = 0;

    /**
     * Felder die beim Import grundsätzlich ignoriert werden (Systemfelder).
     * Ergänzt wird dynamisch durch buildRecordData() anhand des tatsächlichen TCA.
     *
     * @var string[]
     */
    private array $excludedFields = SystemFields::EXCLUDED;

    /** Records pro DataHandler-Batch. */
    private int $batchSize = 500;

    /** @var array<string, array<string, true>> Vorhandene DB-Spalten je Tabelle (Cache). */
    private array $knownColumnsCache = [];

    /** @var array<string, array<string, true>> Relations-Container-Felder je Tabelle (Cache). */
    private array $relationFieldCache = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
        private readonly BootstrapService $bootstrapService,
        private readonly LinkRewriterService $linkRewriterService,
        private readonly FalResolverService $falResolverService,
        private readonly TableRegistryService $tableRegistry,
        private readonly ConfigurationService $configurationService,
        private readonly IntegrityService $integrityService,
        private readonly ImportLockService $importLock,
        private readonly ImportLogRepository $importLogRepository,
        private readonly ConflictResolver $conflictResolver,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly RollbackService $rollbackService,
        private readonly RootlineCacheGuard $rootlineCacheGuard,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Optionen:
     *  dryRun: bool
     *  workspaceId: int
     *  deltaMode: bool
     *  conflict: string   - 'overwrite' (Standard), 'skip', 'ask'
     *  verbose: bool      - Feld-Diff im Log ausgeben
     *  onProgress: callable
     *  onConflictAsk: callable(array $conflict): bool  - Für 'ask'-Modus
     */
    /**
     * @return array{dryRun:bool, importId?:string, stats?:array<string,int>, diff?:array<string,array<string,int>>}
     */
    public function runImport(string $jsonPath, int $targetPid, array $options = []): array
    {
        $dryRun = (bool)($options['dryRun'] ?? false);
        $workspaceId = (int)($options['workspaceId'] ?? 0);

        // Vor dem Backend-Bootstrap registrieren: sobald der Kern den Rootline-Cache
        // instanziert hat, lässt er sich nicht mehr umlenken (siehe RootlineCacheGuard).
        if (!$dryRun) {
            $this->rootlineCacheGuard->defer();
        }

        $this->bootstrapService->initializeBackendContext($workspaceId);
        $this->uidMap = new UidMap();
        $this->createdMap = new UidMap();
        $this->rollbackMap = [];
        $this->dataHandlerErrors = 0;
        $this->batchSize = $this->configurationService->getBatchSize();

        if ($dryRun) {
            $manifest = $this->loadAndValidateJson($jsonPath);
            $diff = $this->runDiffAnalysis($manifest, (bool)($options['verbose'] ?? false));
            $this->logger->info('DRY-RUN BEENDET.');
            return ['dryRun' => true, 'diff' => $diff];
        }

        $lockHandle = $this->importLock->acquire();
        try {
            $result = $this->executeImport($jsonPath, $targetPid, $options);
        } finally {
            $this->rootlineCacheGuard->flush();
            $this->importLock->release($lockHandle);
        }
        return ['dryRun' => false] + $result;
    }

    /**
     * @return array{importId:string, stats:array<string,int>}
     */
    protected function executeImport(string $jsonPath, int $targetPid, array $options): array
    {
        $workspaceId = (int)($options['workspaceId'] ?? 0);
        $deltaMode = (bool)($options['deltaMode'] ?? false);

        $manifest = $this->loadAndValidateJson($jsonPath);
        $this->assertTargetPidExists($targetPid);
        $config = $this->configurationService->getConfig();

        // Stabiler Import-Identifier: wird auch im Fehlerfall für das Notfall-Protokoll genutzt.
        $timestamp = $this->generateImportId();

        try {
            $stats = $this->processImport($manifest, $targetPid, $options, $config);
        } catch (\Throwable $e) {
            if ($this->hasMappedRecords()) {
                $this->handleAbortedImport($timestamp, $jsonPath, $options, $e);
            }
            throw $e;
        }

        $this->importLogRepository->save($timestamp, $workspaceId, $jsonPath, $deltaMode, $this->rollbackMap);
        $this->writeTransactionLog($timestamp, $workspaceId, $jsonPath, $stats, $deltaMode);

        $this->logger->info('Import abgeschlossen', array_merge($stats, ['importId' => $timestamp]));

        return ['importId' => $timestamp, 'stats' => $stats];
    }

    /**
     * Führt die eigentlichen Schreiboperationen aus und liefert die Statistik zurück.
     * Die Protokollierung erfolgt in executeImport() (auch im Fehlerfall).
     *
     * @return array{new:int, updated:int, skipped:int, conflict_skipped:int, errors:int}
     */
    private function processImport(ExportManifest $manifest, int $targetPid, array $options, array $config): array
    {
        $workspaceId = (int)($options['workspaceId'] ?? 0);
        $deltaMode = (bool)($options['deltaMode'] ?? false);
        $conflictStrategy = ConflictStrategy::fromInput($options['conflict'] ?? null);
        $verbose = (bool)($options['verbose'] ?? false);
        $onProgress = $options['onProgress'] ?? null;
        $onConflictAsk = $options['onConflictAsk'] ?? null;

        $pages = $manifest->getPages();
        $ttContent = $manifest->getTtContent();

        $existingPageMap = $this->findExistingRecordsByRemoteUid('pages', $pages);
        $existingContentMap = $this->findExistingRecordsByRemoteUid('tt_content', $ttContent);

        $exportedPageUids = array_column($pages, 'uid');
        $exportedContentUids = array_column($ttContent, 'uid');
        $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'conflict_skipped' => 0, 'errors' => 0];
        // Alt-UID der Übersetzung → Alt-UID des Übersetzungs-Elternteils. Nach dem Batch
        // werden l10n_parent/l18n_parent auf die remappten Eltern-UIDs aufgelöst.
        $l10nFixups = ['pages' => [], 'tt_content' => []];

        $pageDatamap = [];
        foreach ($pages as $page) {
            $oldUid = (int)$page['uid'];
            $recordData = $this->buildRecordData($page, 'pages');
            $recordData['tx_impexpnl_remote_uid'] = $oldUid;

            if ($deltaMode && isset($existingPageMap[$oldUid])) {
                $existingUid = (int)$existingPageMap[$oldUid]['uid'];

                if ($this->conflictResolver->isRecordIdentical($page, $existingPageMap[$oldUid])) {
                    $this->uidMap->set('pages', $oldUid, $existingUid);
                    $stats['skipped']++;
                    continue;
                }

                $conflict = $this->conflictResolver->detectConflict($page, $existingPageMap[$oldUid]);
                if ($conflict && $conflictStrategy === ConflictStrategy::Skip) {
                    $this->uidMap->set('pages', $oldUid, $existingUid);
                    $stats['conflict_skipped']++;
                    $this->logger->warning('Konflikt übersprungen: ' . $conflict);
                    continue;
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Ask && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'pages', 'uid' => $existingUid])) {
                        $this->uidMap->set('pages', $oldUid, $existingUid);
                        $stats['conflict_skipped']++;
                        continue;
                    }
                }

                if ($verbose && $conflict) {
                    $this->conflictResolver->logFieldDiff($page, $existingPageMap[$oldUid], 'pages');
                }

                $pageDatamap[$existingUid] = $recordData;
                $this->uidMap->set('pages', $oldUid, $existingUid);
                $stats['updated']++;
                continue;
            }

            $newIdString = self::NEW_PAGE_PREFIX . $oldUid;
            $oldPid = (int)$page['pid'];
            $recordData['pid'] = in_array($oldPid, $exportedPageUids, true) ? self::NEW_PAGE_PREFIX . $oldPid : $targetPid;

            if (((int)($page['sys_language_uid'] ?? 0) > 0) && !empty($page['l10n_parent']) && $page['l10n_parent'] > 0) {
                $pOld = (int)$page['l10n_parent'];
                $recordData['l10n_parent'] = $this->uidMap->get('pages', $pOld) ?? self::NEW_PAGE_PREFIX . $pOld;
                $l10nFixups['pages'][$oldUid] = $pOld;
            }

            $pageDatamap[$newIdString] = $recordData;
            $stats['new']++;
        }

        $contentDatamap = [];
        foreach ($ttContent as $content) {
            $oldContentUid = (int)$content['uid'];
            $oldPageId = (int)$content['pid'];
            if (!in_array($oldPageId, $exportedPageUids, true)) {
                continue;
            }

            $recordData = $this->buildRecordData($content, 'tt_content');
            $recordData['tx_impexpnl_remote_uid'] = $oldContentUid;

            if ($deltaMode && isset($existingContentMap[$oldContentUid])) {
                $existingUid = (int)$existingContentMap[$oldContentUid]['uid'];

                if ($this->conflictResolver->isRecordIdentical($content, $existingContentMap[$oldContentUid])) {
                    $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                    $stats['skipped']++;
                    continue;
                }

                $conflict = $this->conflictResolver->detectConflict($content, $existingContentMap[$oldContentUid]);
                if ($conflict && $conflictStrategy === ConflictStrategy::Skip) {
                    $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                    $stats['conflict_skipped']++;
                    continue;
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Ask && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'tt_content', 'uid' => $existingUid])) {
                        $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                        $stats['conflict_skipped']++;
                        continue;
                    }
                }

                $contentDatamap[$existingUid] = $recordData;
                $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                $stats['updated']++;
                continue;
            }

            $newId = self::NEW_CONTENT_PREFIX . $oldContentUid;
            $recordData['pid'] = $this->uidMap->get('pages', $oldPageId) ?? self::NEW_PAGE_PREFIX . $oldPageId;

            if (((int)($content['sys_language_uid'] ?? 0) > 0) && !empty($content['l18n_parent']) && $content['l18n_parent'] > 0) {
                $pOld = (int)$content['l18n_parent'];
                $recordData['l18n_parent'] = $this->uidMap->get('tt_content', $pOld) ?? self::NEW_CONTENT_PREFIX . $pOld;
                $l10nFixups['tt_content'][$oldContentUid] = $pOld;
            }

            if (!empty($config['import']['container_support']) && !empty($content['tx_container_parent'])
                && in_array((int)$content['tx_container_parent'], $exportedContentUids, true)) {
                $cOld = (int)$content['tx_container_parent'];
                $recordData['tx_container_parent'] = $this->uidMap->get('tt_content', $cOld) ?? self::NEW_CONTENT_PREFIX . $cOld;
            }

            $contentDatamap[$newId] = $recordData;
            $stats['new']++;
        }

        // Seiten zuerst, damit ihre UIDs für die Content-pids vorliegen.
        $this->executeBatchedDataHandler('pages', $pageDatamap, $onProgress);

        // Platzhalter-pids der Inhalte auf die nun bekannten Seiten-UIDs auflösen.
        foreach ($contentDatamap as $newId => &$recordData) {
            if (!isset($recordData['pid'])) {
                continue;
            }
            if (is_string($recordData['pid']) && str_starts_with($recordData['pid'], self::NEW_PAGE_PREFIX)) {
                $oldPageUid = (int)str_replace(self::NEW_PAGE_PREFIX, '', $recordData['pid']);
                $recordData['pid'] = (int)($this->uidMap->get('pages', $oldPageUid) ?? $targetPid);
            }
            if (!is_int($recordData['pid'])) {
                $recordData['pid'] = (int)$recordData['pid'];
            }
        }
        unset($recordData);

        $this->executeBatchedDataHandler('tt_content', $contentDatamap, $onProgress);

        // l10n_parent/l18n_parent der Übersetzungen auf die remappten Eltern-UIDs auflösen.
        $this->applyL10nFixups($l10nFixups);

        // Sub-Services arbeiten weiterhin auf dem Array (teils mutierend per Referenz).
        // Wir materialisieren einmal, lassen sie ihre Ergänzungen vornehmen und
        // übernehmen das Ergebnis danach zurück in das Value Object.
        $uidArray = $this->uidMap->toArray();

        if (!empty($manifest->getSiteConfig())) {
            $this->adjustSlugsForTargetSite($uidArray);
        }

        $fileReferences = $manifest->getFileReferences();
        if (!empty($config['import']['include']['file_references']) && !empty($fileReferences)) {
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $this->dataHandlerErrors += $this->falResolverService->importReferences($fileReferences, $uidArray, $dh, ['storageId' => $this->configurationService->getFalStorageId(), 'upsert' => $deltaMode]);
        }

        if (!empty($manifest->getIrreRelations())) {
            $this->importInlineRelations($manifest->getIrreRelations(), $uidArray);
        }

        $rawData = $manifest->toArray();
        $this->dataHandlerErrors += $this->tableRegistry->importRegisteredTables($rawData, $uidArray);
        $this->linkRewriterService->rewriteLinks($uidArray, $workspaceId);

        $this->uidMap = UidMap::fromArray($uidArray);
        $this->rollbackMap = $this->buildRollbackMap($uidArray);
        $this->eventDispatcher->dispatch(new ModifyImportDataEvent($rawData, $uidArray));

        $stats['errors'] = $this->dataHandlerErrors;
        return $stats;
    }

    /**
     * Lesbarer Zeitstempel plus Zufalls-Suffix, kollisionssicher auch bei
     * mehreren Importen in derselben Sekunde. Passt in import_id varchar(30).
     */
    private function generateImportId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable $e) {
            $suffix = substr(str_pad((string)mt_rand(0, 16777215), 6, '0', STR_PAD_LEFT), 0, 6);
        }
        return date('Ymd_His') . '_' . $suffix;
    }

    /**
     * Stellt sicher, dass die Ziel-PID existiert (0 = Seitenbaum-Wurzel ist zulässig).
     */
    private function assertTargetPidExists(int $targetPid): void
    {
        if ($targetPid === 0) {
            return;
        }
        if ($targetPid < 0) {
            throw new \RuntimeException("Ungültige Ziel-PID: $targetPid");
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $exists = $qb->count('uid')->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($targetPid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        if (!$exists) {
            throw new \RuntimeException("Ziel-Seite (PID $targetPid) existiert nicht.");
        }
    }

    private function hasMappedRecords(): bool
    {
        return !$this->createdMap->isEmpty();
    }

    /**
     * Protokolliert die Fehler eines DataHandler-Laufs und zählt sie zur
     * Gesamtfehlerzahl des Imports.
     */
    private function recordDataHandlerErrors(DataHandler $dataHandler, string $context): void
    {
        if (empty($dataHandler->errorLog)) {
            return;
        }
        $this->dataHandlerErrors += count($dataHandler->errorLog);
        foreach ($dataHandler->errorLog as $error) {
            $this->logger->error("DataHandler ($context): $error");
        }
    }

    /**
     * Behandelt einen abgebrochenen Import: schreibt ein Protokoll der bereits
     * angelegten Records und rollt diese – sofern aktiviert (Standard) – sofort
     * automatisch zurück, sodass kein halber Baum zurückbleibt.
     */
    private function handleAbortedImport(string $timestamp, string $jsonPath, array $options, \Throwable $e): void
    {
        $this->importLogRepository->save(
            $timestamp,
            (int)($options['workspaceId'] ?? 0),
            $jsonPath . ' [ABGEBROCHEN]',
            (bool)($options['deltaMode'] ?? false),
            $this->createdMap->toArray()
        );

        if ($this->configurationService->isAutoRollbackOnFailure()) {
            try {
                $this->rollbackService->runRollback($timestamp);
                $this->logger->error('Import abgebrochen – Teilimport automatisch zurückgerollt.', [
                    'importId' => $timestamp,
                    'error' => $e->getMessage(),
                ]);
                return;
            } catch (\Throwable $rollbackError) {
                $this->logger->critical('Import abgebrochen UND automatischer Rollback fehlgeschlagen – manueller Eingriff nötig (impexpnl:undo ' . $timestamp . ').', [
                    'importId' => $timestamp,
                    'error' => $e->getMessage(),
                    'rollbackError' => $rollbackError->getMessage(),
                ]);
                return;
            }
        }

        $this->logger->error('Import abgebrochen – Notfall-Protokoll geschrieben (Rollback via impexpnl:undo ' . $timestamp . ' möglich).', [
            'importId' => $timestamp,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Rollback-Basis: ausschließlich neu angelegte Records. Gematchte/aktualisierte
     * Bestands-Records werden bewusst NICHT aufgenommen, damit ein Rollback keine
     * vorbestehenden Daten löscht.
     *
     * @param array<string, array<int,int>> $uidArray vollständige UID-Map nach dem Import
     * @return array<string, array<int,int>>
     */
    private function buildRollbackMap(array $uidArray): array
    {
        $rollback = $this->createdMap->toArray();
        foreach ($uidArray as $table => $entries) {
            // pages/tt_content stammen aus createdMap; sys_file referenziert Bestandsdateien.
            if (in_array($table, ['pages', 'tt_content', 'sys_file'], true)) {
                continue;
            }
            // Registry-Record-Tabellen enthalten nur neu angelegte (NEW_REG) UIDs.
            $rollback[$table] = $entries;
        }
        return $rollback;
    }

    /**
     * Führt einen DataHandler-Lauf in einer DB-Transaktion aus. Ohne sie läuft jede
     * Anweisung im Autocommit (pro Statement ein fsync); bei großen Bäumen kostet das
     * ein Vielfaches. Bei einem Abbruch wird der Teilstand sauber zurückgerollt.
     * Greift voll, solange die beteiligten Tabellen auf einer DB-Connection liegen.
     */
    private function processInTransaction(string $table, callable $process): void
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->beginTransaction();
        try {
            $process();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Verarbeitet die Records in Batches, um den Speicherbedarf bei großen Bäumen zu begrenzen.
     */
    private function executeBatchedDataHandler(string $table, array $datamap, ?callable $onProgress): void
    {
        if (empty($datamap)) {
            return;
        }

        $batches = array_chunk($datamap, $this->batchSize, true);
        $batchCount = count($batches);
        $batchIndex = 0;
        $prefix = $table === 'pages' ? self::NEW_PAGE_PREFIX : self::NEW_CONTENT_PREFIX;

        foreach ($batches as $batch) {
            $this->importLock->refresh();

            // Eltern-UIDs aus früheren Batches auflösen: ein neuer DataHandler kennt
            // nur die Platzhalter des aktuellen Batches. Seiten-interne NEW_PAGE_-Pids
            // bleiben als String, damit der DataHandler sie batchintern auflöst.
            if ($table === 'pages') {
                foreach ($batch as &$record) {
                    if (isset($record['pid']) && is_string($record['pid']) && str_starts_with($record['pid'], self::NEW_PAGE_PREFIX)) {
                        $oldPageUid = (int)str_replace(self::NEW_PAGE_PREFIX, '', $record['pid']);
                        $resolvedUid = $this->uidMap->get('pages', $oldPageUid);
                        if ($resolvedUid !== null) {
                            $record['pid'] = $resolvedUid;
                        }
                    }
                }
                unset($record);
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => $batch], []);
            $this->processInTransaction($table, $dataHandler->process_datamap(...));

            $this->recordDataHandlerErrors($dataHandler, $table);

            foreach ($dataHandler->substNEWwithIDs as $placeholder => $newUid) {
                if (str_starts_with($placeholder, $prefix)) {
                    $oldUid = (int)str_replace($prefix, '', $placeholder);
                    $this->uidMap->set($table, $oldUid, (int)$newUid);
                    $this->createdMap->set($table, $oldUid, (int)$newUid);
                }
            }

            $batchIndex++;
            if ($onProgress) {
                $onProgress("$table Batch $batchIndex/$batchCount", $batchIndex, $batchCount);
            }
        }
    }

    /**
     * Regeneriert die Slug-Felder importierter Seiten für die Ziel-Site.
     */
    private function adjustSlugsForTargetSite(array $uidMap): void
    {
        if (empty($uidMap['pages'])) {
            return;
        }

        try {
            $slugConfig = [];
            if ($this->tcaSchemaFactory->has('pages') && $this->tcaSchemaFactory->get('pages')->hasField('slug')) {
                $slugConfig = $this->tcaSchemaFactory->get('pages')->getField('slug')->getConfiguration();
            }
            $slugHelper = GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\DataHandling\SlugHelper::class,
                'pages',
                'slug',
                $slugConfig
            );
        } catch (\Exception $e) {
            $this->logger->warning('Slug-Regenerierung übersprungen, SlugHelper nicht verfügbar: ' . $e->getMessage());
            return;
        }

        $datamap = [];
        $newUids = array_map('intval', array_values($uidMap['pages']));

        foreach (array_chunk($newUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $pages = $qb->select('*')->from('pages')
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();

            foreach ($pages as $page) {
                try {
                    $newSlug = $this->generateUniqueSlug($slugHelper, $slugConfig, $page);
                    if ($newSlug !== ($page['slug'] ?? '')) {
                        $datamap['pages'][(int)$page['uid']] = ['slug' => $newSlug];
                    }
                } catch (\Exception $e) {
                    // Slug konnte nicht erzeugt werden; bestehenden Wert beibehalten.
                }
            }
        }

        if (!empty($datamap)) {
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start($datamap, []);
            $this->processInTransaction('pages', $dh->process_datamap(...));
            $this->recordDataHandlerErrors($dh, 'slug');
            $this->logger->info('Slugs angepasst', ['count' => count($datamap['pages'])]);
        }
    }

    /**
     * Erzeugt einen für die Ziel-Site eindeutigen Slug, damit beim Einspielen in
     * einen bestehenden Baum keine Slug-Kollisionen entstehen.
     *
     * @param array<string, mixed> $slugConfig
     * @param array<string, mixed> $page
     */
    private function generateUniqueSlug(\TYPO3\CMS\Core\DataHandling\SlugHelper $slugHelper, array $slugConfig, array $page): string
    {
        $pid = (int)$page['pid'];
        $slug = $slugHelper->generate($page, $pid);

        $state = \TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory::forName('pages')
            ->fromArray($page, $pid, (int)$page['uid']);

        $eval = (string)($slugConfig['eval'] ?? '');
        if (str_contains($eval, 'uniqueInSite')) {
            return $slugHelper->buildSlugForUniqueInSite($slug, $state);
        }
        if (str_contains($eval, 'uniqueInPid')) {
            return $slugHelper->buildSlugForUniqueInPid($slug, $state);
        }
        if (str_contains($eval, 'uniqueInTable')) {
            return $slugHelper->buildSlugForUniqueInTable($slug, $state);
        }
        return $slug;
    }

    // =========================================================================
    // HILFSMETHODEN
    // =========================================================================

    protected function loadAndValidateJson(string $jsonPath): ExportManifest
    {
        if (!file_exists($jsonPath)) {
            throw new \RuntimeException("Datei nicht gefunden: $jsonPath");
        }

        // Schutz vor Symlink-/Path-Traversal.
        $realPath = realpath($jsonPath);
        $isJsonl = $realPath !== false && str_ends_with($realPath, '.jsonl');
        if ($realPath === false || (!str_ends_with($realPath, '.json') && !$isJsonl)) {
            throw new \RuntimeException("Ungültiger Dateipfad oder keine JSON/JSONL-Datei: $jsonPath");
        }

        // Mit Verzeichnistrenner vergleichen, damit Nachbarpfade mit gleichem
        // Präfix (/var/www/html-anderes) nicht als "innerhalb" durchgehen.
        $projectPath = Environment::getProjectPath();
        if ($projectPath !== '' && !str_starts_with($realPath, rtrim($projectPath, '/') . '/')) {
            throw new \RuntimeException("Importdatei liegt außerhalb des Projektverzeichnisses: $jsonPath");
        }

        // Prüfung vor dem Parsen: JSON/JSONL wird vollständig in den Speicher
        // geladen; das Limit bricht übergroße Dateien mit Fehlermeldung ab,
        // bevor der Prozess in ein OOM läuft.
        $maxBytes = $this->configurationService->getImportMaxFileSizeBytes();
        $fileSize = (int)filesize($realPath);
        if ($maxBytes > 0 && $fileSize > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'Importdatei zu groß: %d MB (Limit: %d MB, konfigurierbar über import.max_file_size_mb; 0 = unbegrenzt). '
                . 'Datei prüfen, Limit erhöhen oder den Export aufteilen (--pages/--since).',
                (int)round($fileSize / 1048576),
                (int)round($maxBytes / 1048576)
            ));
        }
        $this->assertMemoryHeadroom($fileSize);

        $data = $isJsonl ? $this->parseJsonlFile($realPath) : $this->parseJsonFile($realPath);

        $manifest = ExportManifest::fromArray($data);
        if (!$manifest->hasPages()) {
            throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');
        }

        $checksum = $manifest->getChecksum();

        // Signaturpflicht (import.require_signature): ohne gültige HMAC-Signatur wird
        // gar nicht erst verifiziert, sondern sofort abgewiesen. Verhindert sowohl das
        // Weglassen der Prüfsumme als auch den Downgrade auf eine bloße sha256-Prüfsumme.
        if ($this->configurationService->isSignatureRequired()) {
            if (!$this->integrityService->hasSigningKey()) {
                throw new \RuntimeException(
                    'import.require_signature ist aktiv, aber es ist kein Signing-Key konfiguriert '
                    . '(IMPEXPNL_SIGNING_KEY bzw. EXTENSIONS/imp_exp_nl/signingKey). Import nicht möglich.'
                );
            }
            if (!$this->integrityService->isHmacSignature($checksum)) {
                throw new \RuntimeException(
                    'Signaturpflicht aktiv (import.require_signature): Die Importdatei trägt keine '
                    . 'HMAC-Signatur (hmac-sha256:). Datei auf dem Quellsystem mit identischem '
                    . 'Signing-Key neu exportieren.'
                );
            }
        }

        if ($checksum !== null && !$this->integrityService->verify($manifest->toArray(), $checksum)) {
            throw new \RuntimeException(
                'Integritätsprüfung fehlgeschlagen: JSON wurde verändert oder die Signatur kann ohne '
                . 'konfigurierten Schlüssel (IMPEXPNL_SIGNING_KEY) nicht verifiziert werden.'
            );
        }
        return $manifest;
    }

    /**
     * Geschätzter Speicherfaktor: dekodiertes PHP-Array vs. JSON-Dateigröße.
     */
    private const MEMORY_FACTOR = 8;

    /**
     * Plausibilitätsprüfung gegen das PHP memory_limit: Das dekodierte Array
     * benötigt ein Vielfaches der Dateigröße; ohne Prüfung endet das Parsen
     * in einem OOM-Fatal ohne verwertbare Fehlermeldung.
     */
    private function assertMemoryHeadroom(int $fileSize): void
    {
        $limit = $this->parseMemoryLimit((string)ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // -1 = unbegrenzt
        }
        $estimated = memory_get_usage(true) + $fileSize * self::MEMORY_FACTOR;
        if ($estimated > $limit) {
            throw new \RuntimeException(sprintf(
                'PHP memory_limit (%s) reicht voraussichtlich nicht für das Parsen der %d-MB-Importdatei '
                . '(geschätzter Bedarf ~%d MB). memory_limit erhöhen (z. B. php -d memory_limit=1G vendor/bin/typo3 …) '
                . 'oder den Export aufteilen (--pages/--since).',
                (string)ini_get('memory_limit'),
                (int)round($fileSize / 1048576),
                (int)round($estimated / 1048576)
            ));
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int)$limit;
        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonFile(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON-Fehler: ' . json_last_error_msg());
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Liest eine JSONL-Datei zeilenweise ein (ein JSON-Objekt pro Zeile),
     * ohne die gesamte Datei als einen String zu dekodieren.
     *
     * @return array<string, mixed>
     */
    private function parseJsonlFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Datei nicht lesbar: $path");
        }
        $data = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
                    throw new \RuntimeException('JSONL-Fehler: ungültige Zeile.');
                }
                if (array_key_exists('_meta', $obj)) {
                    $data['_meta'] = $obj['_meta'];
                    continue;
                }
                if (isset($obj['_t']) && array_key_exists('_r', $obj)) {
                    $data[(string)$obj['_t']][] = $obj['_r'];
                }
            }
        } finally {
            fclose($handle);
        }
        return $data;
    }

    private function findExistingRecordsByRemoteUid(string $table, array $records): array
    {
        $uids = array_column($records, 'uid');
        if (empty($uids)) {
            return [];
        }
        $map = [];
        foreach (array_chunk($uids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $rows = $qb->select('*')->from($table)
                ->where($qb->expr()->in('tx_impexpnl_remote_uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $map[(int)$r['tx_impexpnl_remote_uid']] = $r;
            }
        }
        return $map;
    }

    /**
     * Baut die Record-Daten für den DataHandler und filtert dabei Felder heraus,
     * die in der Zieltabelle nicht (mehr) existieren.
     */
    protected function buildRecordData(array $source, string $table = ''): array
    {
        $knownColumns = $table !== '' ? $this->getKnownColumns($table) : [];
        // Relations-Container-Felder (inline/file/category) enthalten im Export nur einen
        // Zähler statt einer UID-Liste. Als Datamap-Wert übergeben, brechen sie bei
        // Übersetzungen den DataMapProcessor (trimExplode erwartet string, bekommt int).
        $relationFields = $table !== '' ? $this->getRelationContainerFields($table) : [];
        $data = [];
        foreach ($source as $field => $value) {
            if (in_array($field, $this->excludedFields, true)) {
                continue;
            }
            // Wenn Spalten-Info vorhanden: nur tatsächlich existierende Felder übernehmen
            if (!empty($knownColumns) && !isset($knownColumns[$field])) {
                continue;
            }
            if (isset($relationFields[$field])) {
                continue;
            }
            $data[$field] = $value;
        }
        return $data;
    }

    /**
     * Schreibt l10n_parent/l18n_parent der importierten Übersetzungen auf die
     * remappten Eltern-UIDs um (NEW_-Platzhalter werden vom DataHandler nicht
     * zuverlässig in diesen Feldern aufgelöst).
     *
     * @param array{pages: array<int,int>, tt_content: array<int,int>} $fixups
     */
    private function applyL10nFixups(array $fixups): void
    {
        foreach (['pages' => 'l10n_parent', 'tt_content' => 'l18n_parent'] as $table => $parentField) {
            foreach ($fixups[$table] as $oldUid => $oldParentUid) {
                $newUid = $this->uidMap->get($table, (int)$oldUid);
                $newParent = $this->uidMap->get($table, (int)$oldParentUid);
                if ($newUid === null || $newParent === null) {
                    continue;
                }
                $this->connectionPool->getConnectionForTable($table)
                    ->update($table, [$parentField => $newParent], ['uid' => $newUid]);
            }
        }
    }

    /**
     * Relations-Container-Felder (inline/file/category) einer Tabelle (gecacht).
     *
     * @return array<string, true> Feldname → true
     */
    private function getRelationContainerFields(string $table): array
    {
        if (isset($this->relationFieldCache[$table])) {
            return $this->relationFieldCache[$table];
        }
        $fields = [];
        try {
            if ($this->tcaSchemaFactory->has($table)) {
                foreach ($this->tcaSchemaFactory->get($table)->getFields() as $field) {
                    if (
                        $field->isType(TableColumnType::INLINE)
                        || $field->isType(TableColumnType::FILE)
                        || $field->isType(TableColumnType::CATEGORY)
                    ) {
                        $fields[$field->getName()] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // TCA nicht verfügbar -> keine Filterung.
        }
        $this->relationFieldCache[$table] = $fields;
        return $fields;
    }

    /**
     * Ermittelt die tatsächlich vorhandenen DB-Spalten einer Tabelle (gecacht).
     *
     * @return array<string, true> Spaltenname → true
     */
    private function getKnownColumns(string $table): array
    {
        if (isset($this->knownColumnsCache[$table])) {
            return $this->knownColumnsCache[$table];
        }
        try {
            $schemaManager = $this->connectionPool
                ->getConnectionForTable($table)
                ->createSchemaManager();
            // DBAL 4 (TYPO3 v13): listTableColumns() entfernt → introspectTable() verwenden.
            $columns = $schemaManager->introspectTable($table)->getColumns();
            $map = [];
            foreach ($columns as $col) {
                $map[$col->getName()] = true;
            }
            $this->knownColumnsCache[$table] = $map;
        } catch (\Exception $e) {
            $this->knownColumnsCache[$table] = [];
        }
        return $this->knownColumnsCache[$table];
    }

    /**
     * @param array<string, array<int,int>> $uidMap
     */
    private function importInlineRelations(array $irreRelations, array $uidMap): void
    {
        if (empty($irreRelations)) {
            return;
        }
        $datamap = [];
        $count = 0;
        foreach ($irreRelations as $rel) {
            $table = $rel['table'] ?? '';
            $ff = $rel['foreign_field'] ?? '';
            $rec = $rel['record'] ?? [];
            if (empty($table) || empty($ff) || empty($rec)) {
                continue;
            }
            $oldParent = (int)($rec[$ff] ?? 0);
            $newParent = $uidMap['tt_content'][$oldParent] ?? null;
            if ($newParent === null) {
                continue;
            }

            $child = [];
            foreach ($rec as $f => $v) {
                if (!in_array($f, $this->excludedFields, true)) {
                    $child[$f] = $v;
                }
            }
            $child[$ff] = $newParent;
            // PID-Containment: kein Fallback auf die unveränderte Quell-PID –
            // IRRE-Kinder ohne gemappte Ziel-Seite werden übersprungen.
            $newPid = $uidMap['pages'][$rec['pid'] ?? 0] ?? null;
            if ($newPid === null) {
                $this->logger->warning('IRRE-Relation übersprungen: Ziel-Seite nicht auflösbar (PID-Containment).', [
                    'table' => $table,
                    'source_uid' => $rec['uid'] ?? null,
                ]);
                continue;
            }
            $child['pid'] = $newPid;
            $datamap[$table]['NEW_IRRE_' . $table . '_' . ($rec['uid'] ?? $count)] = $child;
            $count++;
        }
        if (!empty($datamap)) {
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start($datamap, []);
            $this->processInTransaction($table, $dh->process_datamap(...));
            $this->recordDataHandlerErrors($dh, 'irre');
        }
    }

    // =========================================================================
    // DIFF-ANALYSE
    // =========================================================================

    /**
     * @return array{pages:array{new:int,changed:int,identical:int}, tt_content:array{new:int,changed:int,identical:int}}
     */
    protected function runDiffAnalysis(ExportManifest $manifest, bool $verbose): array
    {
        $this->logger->info('=== DIFFERENZ-ANALYSE ===');
        $pages = $manifest->getPages();
        $ttContent = $manifest->getTtContent();

        $eP = $this->findExistingRecordsByRemoteUid('pages', $pages);
        $nP = $uP = $iP = 0;
        foreach ($pages as $p) {
            $id = (int)$p['uid'];
            if (!isset($eP[$id])) {
                $nP++;
                $this->logger->info('<fg=green>+ NEU</>        | ' . ($p['title'] ?? ''));
            } elseif (!$this->conflictResolver->isRecordIdentical($p, $eP[$id])) {
                $uP++;
                $this->logger->info('<fg=yellow>~ GEÄNDERT</>   | ' . ($p['title'] ?? ''));
                if ($verbose) {
                    $this->conflictResolver->logFieldDiff($p, $eP[$id], 'pages');
                }
            } else {
                $iP++;
                $this->logger->info('<fg=blue>= IDENTISCH</>  | ' . ($p['title'] ?? ''));
            }
        }

        $eC = $this->findExistingRecordsByRemoteUid('tt_content', $ttContent);
        $nC = $uC = $iC = 0;
        foreach ($ttContent as $c) {
            $id = (int)$c['uid'];
            if (!isset($eC[$id])) {
                $nC++;
            } elseif (!$this->conflictResolver->isRecordIdentical($c, $eC[$id])) {
                $uC++;
                if ($verbose) {
                    $this->conflictResolver->logFieldDiff($c, $eC[$id], 'tt_content');
                }
            } else {
                $iC++;
            }
        }

        $this->logger->info(sprintf('Seiten:  %d neu, %d geändert, %d identisch.', $nP, $uP, $iP));
        $this->logger->info(sprintf('Inhalte: %d neu, %d geändert, %d identisch.', $nC, $uC, $iC));
        if ($iP + $iC > 0) {
            $this->logger->info('Tipp: Mit --delta werden identische Records übersprungen.');
        }

        return [
            'pages' => ['new' => $nP, 'changed' => $uP, 'identical' => $iP],
            'tt_content' => ['new' => $nC, 'changed' => $uC, 'identical' => $iC],
        ];
    }

    private function writeTransactionLog(string $id, int $ws, string $file, array $stats, bool $delta): void
    {
        $logDir = Environment::getVarPath() . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $mode = $delta ? 'DELTA' : 'VOLL';
        $lines = [str_repeat('=', 72), sprintf('[%s] %s-IMPORT %s', date('Y-m-d H:i:s'), $mode, $id), str_repeat('-', 72),
            sprintf('Quelldatei: %s | Workspace: %d', $file, $ws),
            sprintf('Neu: %d | Aktualisiert: %d | Übersprungen: %d | Konflikte übersprungen: %d', $stats['new'], $stats['updated'], $stats['skipped'], $stats['conflict_skipped'] ?? 0), ''];
        foreach (['pages', 'tt_content'] as $t) {
            $entries = $this->uidMap->forTable($t);
            if (!empty($entries)) {
                $lines[] = "$t:";
                foreach ($entries as $o => $n) {
                    $lines[] = "  $o → $n";
                }
            }
        }
        $lines[] = '';
        $lines[] = "Undo: vendor/bin/typo3 impexpnl:undo $id";
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        file_put_contents($logDir . '/impexpnl_transactions.log', implode("\n", $lines), FILE_APPEND | LOCK_EX);
    }
}
