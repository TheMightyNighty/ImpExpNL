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
use Robbi\ImpExpNL\Domain\ManifestReader;
use Robbi\ImpExpNL\Domain\SystemFields;
use Robbi\ImpExpNL\Domain\UidMap;
use Robbi\ImpExpNL\Event\ModifyImportDataEvent;
use Robbi\ImpExpNL\Exception\AbortedImportException;
use Robbi\ImpExpNL\Exception\ConfigException;
use Robbi\ImpExpNL\Exception\ConflictException;
use Robbi\ImpExpNL\Exception\ImpExpException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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

    /** @var list<int> Vom Bundle-Import neu platzierte sys_file-UIDs (Basis für Asset-GC). */
    private array $placedFileUids = [];

    /** Laufender Import-Kontext für das Checkpointing (SIGKILL-Robustheit). */
    private string $currentImportId = '';
    private int $currentWorkspaceId = 0;
    private string $currentSourceFile = '';
    private bool $currentDelta = false;

    /** Anzahl der vom DataHandler gemeldeten Fehler. */
    private int $dataHandlerErrors = 0;

    /**
     * Laufende Import-Statistik (transient während processImport). Als Member-Feld,
     * damit die über Closures aufgerufenen Batch-Builder sie fortschreiben können.
     *
     * @var array<string, int>
     */
    private array $importStats = [];

    /**
     * l10n-Eltern-Nachpass: Quell-UID => Quell-Eltern-UID (transient während processImport).
     *
     * @var array{pages: array<int,int>, tt_content: array<int,int>}
     */
    private array $l10nFixups = ['pages' => [], 'tt_content' => []];

    /**
     * Systemfelder, die beim Record-Vergleich (IRRE, Diff) ignoriert werden.
     *
     * @var string[]
     */
    private array $excludedFields = SystemFields::EXCLUDED;

    /** Records pro DataHandler-Batch. */
    private int $batchSize = 500;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
        private readonly BootstrapService $bootstrapService,
        private readonly LinkRewriterService $linkRewriterService,
        private readonly FalResolverService $falResolverService,
        private readonly TableRegistryService $tableRegistry,
        private readonly ConfigurationService $configurationService,
        private readonly ImportLockService $importLock,
        private readonly ImportLogRepository $importLogRepository,
        private readonly ConflictResolver $conflictResolver,
        private readonly RollbackService $rollbackService,
        private readonly UidMapRepository $uidMapRepository,
        private readonly AssetManifestService $assetManifestService,
        private readonly TransferProfileService $transferProfileService,
        private readonly UrlRewriteService $urlRewriteService,
        private readonly BundleImportService $bundleImportService,
        private readonly ManifestLoaderService $manifestLoader,
        private readonly RecordDataBuilder $recordDataBuilder,
        private readonly ExistingRecordLocator $existingRecordLocator,
        private readonly DiffAnalysisService $diffAnalysisService,
        private readonly SlugRegenerationService $slugRegenerationService,
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
     * @return array{dryRun:bool, importId?:string, stats?:array<string,int>, diff?:array<string,mixed>, assets?:array{total:int, missing:list<string>, mismatch:list<string>}}
     */
    public function runImport(string $jsonPath, int $targetPid, array $options = []): array
    {
        $dryRun = (bool)($options['dryRun'] ?? false);
        $workspaceId = (int)($options['workspaceId'] ?? 0);

        // Transfer-Profil: Umgebungs-Bindung + Guardrails durchsetzen, Konflikt-Strategie übernehmen.
        if (!empty($options['transferProfile'])) {
            $profile = $this->transferProfileService->resolve((string)$options['transferProfile']);
            $this->transferProfileService->assertContextAllowed($profile);
            if ($profile->requireDryRun && !$dryRun) {
                throw new ConfigException(sprintf(
                    "Transfer-Profil '%s' erlaubt nur Dry-Run (requireDryRun). Mit --dry-run ausführen.",
                    $profile->name
                ));
            }
            $options['conflict'] = $profile->conflictStrategy;
            $options['allowedTables'] = $profile->allowedTables;
            $this->logger->info('Transfer-Profil aktiv', [
                'profile' => $profile->name,
                'conflict' => $profile->conflictStrategy->value,
                'allowedTables' => $profile->allowedTables ?? '(alle)',
            ]);
        }

        // Vor dem Backend-Bootstrap registrieren: sobald der Kern den Rootline-Cache
        // instanziert hat, lässt er sich nicht mehr umlenken (siehe RootlineCacheGuard).
        if (!$dryRun) {
            $this->rootlineCacheGuard->defer();
        }

        $this->bootstrapService->initializeBackendContext($workspaceId);

        // Bundle (.tar.gz) entpacken: Asset-Dateien ins Ziel legen (außer Dry-Run),
        // dann mit der enthaltenen Export-Datei weiterarbeiten. Das transiente
        // Entpack-Verzeichnis wird am Ende verlässlich aufgeräumt (sonst bleibt pro
        // Import die volle entpackte Kopie liegen – auch bei Abbruch).
        $bundleTempDir = null;
        $this->placedFileUids = [];
        $this->currentImportId = '';
        if ($this->bundleImportService->isBundle($jsonPath)) {
            $jsonPath = $this->bundleImportService->extract($jsonPath);
            $bundleTempDir = dirname($jsonPath);
        }

        try {
            $this->uidMap = new UidMap();
            $this->createdMap = new UidMap();
            $this->rollbackMap = [];
            $this->dataHandlerErrors = 0;
            $this->batchSize = $this->configurationService->getBatchSize();

            // Bundle-Assets erst NACH erfolgreicher Integritäts-/Signaturprüfung der
            // Export-Datei platzieren. Quelle ist ausschließlich der signierte
            // `assets`-Block; jede Datei wird gegen ihre signierte sha256 verifiziert.
            // Das Protokoll wird schon VOR der Platzierung scharf geschaltet und die
            // platzierten UIDs inkrementell fortgeschrieben – so ist auch ein Crash
            // WÄHREND der Asset-Platzierung per undo rückrollbar.
            if ($bundleTempDir !== null && !$dryRun) {
                $verifiedManifest = $this->loadAndValidateJson($jsonPath);
                $this->currentImportId = $this->generateImportId();
                $this->currentWorkspaceId = $workspaceId;
                $this->currentSourceFile = $jsonPath;
                $this->currentDelta = (bool)($options['deltaMode'] ?? false);
                $this->bundleImportService->placeAssets(
                    $bundleTempDir,
                    $verifiedManifest->getAssets(),
                    function (array $uids): void {
                        $this->placedFileUids = $uids;
                        $this->checkpointImportLog();
                    }
                );
                $this->placedFileUids = $this->bundleImportService->getPlacedFileUids();
            }

            if ($dryRun) {
                $manifest = $this->loadAndValidateJson($jsonPath);
                $diff = $this->diffAnalysisService->analyze($manifest, (bool)($options['verbose'] ?? false));
                $assets = $this->buildAssetPreflight($manifest->getAssets());
                $this->logger->info('DRY-RUN BEENDET.');
                return [
                    'dryRun' => true,
                    'diff' => $diff,
                    'assets' => $assets,
                    'preview' => [
                        'package' => basename($jsonPath),
                        'checksum' => $manifest->getChecksum(),
                        'delta' => (bool)($options['deltaMode'] ?? false),
                        'lockFree' => $this->importLock->getActiveLock() === null,
                    ],
                ];
            }

            $lockHandle = $this->importLock->acquire();
            try {
                $result = $this->executeImport($jsonPath, $targetPid, $options);
            } finally {
                $this->rootlineCacheGuard->flush();
                $this->importLock->release($lockHandle);
            }
            return ['dryRun' => false] + $result;
        } finally {
            if ($bundleTempDir !== null) {
                $this->bundleImportService->cleanupTransient($bundleTempDir);
            }
        }
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

        // Stabiler Import-Identifier: wurde beim Bundle-Import bereits vor der Platzierung
        // erzeugt (Checkpointing); sonst hier neu. Wird auch im Fehlerfall fürs Protokoll genutzt.
        $timestamp = $this->currentImportId !== '' ? $this->currentImportId : $this->generateImportId();

        // Kontext + Erst-Checkpoint VOR dem ersten Batch: schreibt bereits die platzierten
        // Assets ins Protokoll und legt die Zeile an, die danach pro Batch fortgeschrieben wird.
        // So ist ein Teilimport auch nach hartem Crash (SIGKILL, OOM) per undo rückrollbar.
        $this->currentImportId = $timestamp;
        $this->currentWorkspaceId = $workspaceId;
        $this->currentSourceFile = $jsonPath;
        $this->currentDelta = $deltaMode;
        $this->checkpointImportLog();

        try {
            $stats = $this->processImport($manifest, $targetPid, $options, $config);
        } catch (\Throwable $e) {
            if ($this->hasMappedRecords()) {
                $this->handleAbortedImport($timestamp, $jsonPath, $options, $e);
                // Typisierte Ursachen (z. B. Konflikt = 5) behalten ihren Exit-Code;
                // ein sonstiger Crash mit Teilimport-Rollback signalisiert Exit-Code 6.
                if ($e instanceof ImpExpException) {
                    throw $e;
                }
                throw new AbortedImportException($e->getMessage(), $e);
            }
            throw $e;
        }

        // ImportLog (per Upsert, aktualisiert die früh angelegte Checkpoint-Zeile) trägt
        // zusätzlich die platzierten Assets (für den Rollback-GC); die UID-Map-Persistenz
        // bekommt die unveränderte Tabellen-Map (kein Pseudo-Eintrag).
        $this->importLogRepository->upsert($timestamp, $workspaceId, $jsonPath, $deltaMode, $this->withPlacedFiles($this->rollbackMap));
        $this->uidMapRepository->persist($manifest->getSourceId(), $timestamp, $this->rollbackMap);
        $this->writeTransactionLog($timestamp, $workspaceId, $jsonPath, $stats, $deltaMode);

        $this->logger->info('Import abgeschlossen', array_merge($stats, ['importId' => $timestamp]));

        return ['importId' => $timestamp, 'stats' => $stats];
    }

    /**
     * Führt die eigentlichen Schreiboperationen aus und liefert die Statistik zurück.
     * Die Protokollierung erfolgt in executeImport() (auch im Fehlerfall).
     *
     * @return array{new:int, updated:int, skipped:int, conflict_skipped:int, errors:int, assets_missing:int}
     */
    private function processImport(ManifestReader $manifest, int $targetPid, array $options, array $config): array
    {
        $workspaceId = (int)($options['workspaceId'] ?? 0);
        $deltaMode = (bool)($options['deltaMode'] ?? false);
        $conflictStrategy = ConflictStrategy::fromInput($options['conflict'] ?? null);
        $verbose = (bool)($options['verbose'] ?? false);
        $onProgress = $options['onProgress'] ?? null;
        $onConflictAsk = $options['onConflictAsk'] ?? null;

        $sourceId = $manifest->getSourceId();

        // Containment-Prüfungen brauchen nur die uid-Mengen (ints) – nicht den
        // Record-Inhalt. Als Set (uid => true) für O(1)-Lookups.
        $exportedPageUids = array_fill_keys($manifest->collectUids('pages'), true);
        $exportedContentUids = array_fill_keys($manifest->collectUids('tt_content'), true);

        // Statistik + l10n-Nachpass liegen für die Dauer der Verarbeitung in
        // Member-Feldern (die Batch-Builder werden über Closures aufgerufen; per-Wert
        // capturende Arrow-Funktionen könnten sie sonst nicht zurückschreiben).
        $this->importStats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'conflict_skipped' => 0, 'errors' => 0, 'assets_missing' => 0];
        $this->l10nFixups = ['pages' => [], 'tt_content' => []];

        // Seiten zuerst (streamend in Batches), damit ihre UIDs für die Content-pids
        // vorliegen. Peak = ein Batch Record-Content statt des ganzen Baums.
        $pageBatchTotal = (int)ceil(count($exportedPageUids) / max(1, $this->batchSize));
        $this->streamRecordsInBatches(
            $manifest,
            'pages',
            $pageBatchTotal,
            $onProgress,
            fn(array $batch): array => $this->buildPageBatchDatamap(
                $batch,
                $sourceId,
                $targetPid,
                $exportedPageUids,
                $deltaMode,
                $conflictStrategy,
                $verbose,
                $onConflictAsk
            )
        );

        // Inhalte (streamend): alle Seiten sind bereits verarbeitet, ihre Ziel-UIDs
        // stehen in der uidMap → Content-pids werden direkt aufgelöst.
        $contentBatchTotal = (int)ceil(count($exportedContentUids) / max(1, $this->batchSize));
        $this->streamRecordsInBatches(
            $manifest,
            'tt_content',
            $contentBatchTotal,
            $onProgress,
            fn(array $batch): array => $this->buildContentBatchDatamap(
                $batch,
                $sourceId,
                $targetPid,
                $exportedPageUids,
                $exportedContentUids,
                $deltaMode,
                $conflictStrategy,
                $onConflictAsk,
                $config
            )
        );

        $stats = $this->importStats;

        // l10n-Eltern auflösen: der NEW_-Platzhalter im l10n_parent/l18n_parent wird vom
        // DataHandler nicht ersetzt, daher hier mit den nun bekannten Ziel-UIDs nachziehen.
        $this->applyL10nFixups($this->l10nFixups);

        // Sub-Services arbeiten weiterhin auf dem Array (teils mutierend per Referenz).
        // Wir materialisieren einmal, lassen sie ihre Ergänzungen vornehmen und
        // übernehmen das Ergebnis danach zurück in das Value Object.
        $uidArray = $this->uidMap->toArray();

        if (!empty($manifest->getSiteConfig())) {
            $this->dataHandlerErrors += $this->slugRegenerationService->regenerate($uidArray);
        }

        $fileReferences = $manifest->getFileReferences();
        if (!empty($config['import']['include']['file_references']) && !empty($fileReferences)) {
            $stats['assets_missing'] = $this->warnOnAssetIntegrityIssues($manifest->getAssets());
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $this->dataHandlerErrors += $this->falResolverService->importReferences($fileReferences, $uidArray, $dh, ['storageId' => $this->configurationService->getFalStorageId(), 'upsert' => $deltaMode]);
        }

        if (!empty($manifest->getIrreRelations())) {
            $this->importInlineRelations($manifest->getIrreRelations(), $uidArray);
        }

        // Registry + relationale Bereiche: die kleineren Tabellen (kein pages/tt_content-
        // Bulk). Im Streaming-Fall einmalig materialisiert, im JSON-Fall aus dem Array.
        $auxData = $manifest->getAuxiliaryData();
        $allowedTables = $options['allowedTables'] ?? null;
        $createdRegistry = [];
        $this->dataHandlerErrors += $this->tableRegistry->importRegisteredTables(
            $auxData,
            $uidArray,
            is_array($allowedTables) ? $allowedTables : null,
            $sourceId,
            $deltaMode,
            $createdRegistry
        );
        $this->linkRewriterService->rewriteLinks($uidArray, $workspaceId);
        $this->rewriteAbsoluteUrls($uidArray, $config, $workspaceId);

        $this->uidMap = UidMap::fromArray($uidArray);
        $this->rollbackMap = $this->buildRollbackMap($uidArray, $createdRegistry);
        // Post-Import-Event: die vollständige UID-Map plus die relationalen Aux-Daten.
        // pages/tt_content-Bulk wird bewusst nicht erneut mitgegeben – ihr Ergebnis
        // steht vollständig in der UID-Map (siehe ManifestReader::getAuxiliaryData()).
        $this->eventDispatcher->dispatch(new ModifyImportDataEvent($auxData, $uidArray));

        $stats['errors'] = $this->dataHandlerErrors;
        return $stats;
    }

    /**
     * Streamt die Records einer Tabelle in Batches und führt jeden Batch sofort aus.
     * Peak-Speicher = ein Batch Record-Content (die Datei selbst wird nicht komplett
     * gehalten). `$buildDatamap` baut aus einem rohen Record-Batch das DataHandler-Datamap.
     *
     * @param callable(array<int, array<string,mixed>>): array<string|int, array<string,mixed>> $buildDatamap
     */
    private function streamRecordsInBatches(ManifestReader $manifest, string $table, int $batchTotal, ?callable $onProgress, callable $buildDatamap): void
    {
        $batch = [];
        $index = 0;
        foreach ($manifest->eachRecord($table) as $record) {
            $batch[] = $record;
            if (count($batch) >= $this->batchSize) {
                $this->executeDataHandlerBatch($table, $buildDatamap($batch), $onProgress, ++$index, $batchTotal);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->executeDataHandlerBatch($table, $buildDatamap($batch), $onProgress, ++$index, $batchTotal);
        }
    }

    /**
     * Baut das Seiten-Datamap eines Batches (Delta/Konflikt/Neu) und schreibt
     * Statistik und l10n-Nachpass in die Member-Felder fort.
     *
     * @param array<int, array<string,mixed>> $batch
     * @param array<int, true> $exportedPageUids
     * @return array<string|int, array<string,mixed>>
     */
    private function buildPageBatchDatamap(array $batch, string $sourceId, int $targetPid, array $exportedPageUids, bool $deltaMode, ConflictStrategy $conflictStrategy, bool $verbose, ?callable $onConflictAsk): array
    {
        $existingPageMap = $deltaMode ? $this->existingRecordLocator->find($sourceId, 'pages', $batch) : [];
        $datamap = [];
        foreach ($batch as $page) {
            $oldUid = (int)$page['uid'];
            $recordData = $this->recordDataBuilder->build($page, 'pages');

            if ($deltaMode && isset($existingPageMap[$oldUid])) {
                $existingUid = (int)$existingPageMap[$oldUid]['uid'];

                if ($this->conflictResolver->isRecordIdentical($page, $existingPageMap[$oldUid])) {
                    $this->uidMap->set('pages', $oldUid, $existingUid);
                    $this->importStats['skipped']++;
                    continue;
                }

                $conflict = $this->conflictResolver->detectConflict($page, $existingPageMap[$oldUid]);
                if ($conflict && $conflictStrategy === ConflictStrategy::Abort) {
                    throw new ConflictException('Konflikt (pages uid=' . $existingUid . '): ' . $conflict . ' — Import abgebrochen (conflict=abort).');
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Skip) {
                    $this->uidMap->set('pages', $oldUid, $existingUid);
                    $this->importStats['conflict_skipped']++;
                    $this->logger->warning('Konflikt übersprungen: ' . $conflict);
                    continue;
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Ask && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'pages', 'uid' => $existingUid])) {
                        $this->uidMap->set('pages', $oldUid, $existingUid);
                        $this->importStats['conflict_skipped']++;
                        continue;
                    }
                }

                if ($verbose && $conflict) {
                    $this->conflictResolver->logFieldDiff($page, $existingPageMap[$oldUid], 'pages');
                }

                $datamap[$existingUid] = $recordData;
                $this->uidMap->set('pages', $oldUid, $existingUid);
                $this->importStats['updated']++;
                continue;
            }

            $oldPid = (int)$page['pid'];
            $recordData['pid'] = isset($exportedPageUids[$oldPid]) ? self::NEW_PAGE_PREFIX . $oldPid : $targetPid;

            if (((int)($page['sys_language_uid'] ?? 0) > 0) && !empty($page['l10n_parent']) && $page['l10n_parent'] > 0) {
                $pOld = (int)$page['l10n_parent'];
                $recordData['l10n_parent'] = $this->uidMap->get('pages', $pOld) ?? self::NEW_PAGE_PREFIX . $pOld;
                $this->l10nFixups['pages'][$oldUid] = $pOld;
            }

            $datamap[self::NEW_PAGE_PREFIX . $oldUid] = $recordData;
            $this->importStats['new']++;
        }
        return $datamap;
    }

    /**
     * Baut das Inhalts-Datamap eines Batches. Da die Seiten vollständig verarbeitet
     * sind, werden Content-pids direkt aus der uidMap aufgelöst.
     *
     * @param array<int, array<string,mixed>> $batch
     * @param array<int, true> $exportedPageUids
     * @param array<int, true> $exportedContentUids
     * @return array<string|int, array<string,mixed>>
     */
    private function buildContentBatchDatamap(array $batch, string $sourceId, int $targetPid, array $exportedPageUids, array $exportedContentUids, bool $deltaMode, ConflictStrategy $conflictStrategy, ?callable $onConflictAsk, array $config): array
    {
        $existingContentMap = $deltaMode ? $this->existingRecordLocator->find($sourceId, 'tt_content', $batch) : [];
        $datamap = [];
        foreach ($batch as $content) {
            $oldContentUid = (int)$content['uid'];
            $oldPageId = (int)$content['pid'];
            if (!isset($exportedPageUids[$oldPageId])) {
                continue;
            }

            $recordData = $this->recordDataBuilder->build($content, 'tt_content');

            if ($deltaMode && isset($existingContentMap[$oldContentUid])) {
                $existingUid = (int)$existingContentMap[$oldContentUid]['uid'];

                if ($this->conflictResolver->isRecordIdentical($content, $existingContentMap[$oldContentUid])) {
                    $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                    $this->importStats['skipped']++;
                    continue;
                }

                $conflict = $this->conflictResolver->detectConflict($content, $existingContentMap[$oldContentUid]);
                if ($conflict && $conflictStrategy === ConflictStrategy::Abort) {
                    throw new ConflictException('Konflikt (tt_content uid=' . $existingUid . '): ' . $conflict . ' — Import abgebrochen (conflict=abort).');
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Skip) {
                    $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                    $this->importStats['conflict_skipped']++;
                    continue;
                }
                if ($conflict && $conflictStrategy === ConflictStrategy::Ask && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'tt_content', 'uid' => $existingUid])) {
                        $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                        $this->importStats['conflict_skipped']++;
                        continue;
                    }
                }

                $datamap[$existingUid] = $recordData;
                $this->uidMap->set('tt_content', $oldContentUid, $existingUid);
                $this->importStats['updated']++;
                continue;
            }

            // Seiten sind fertig → pid direkt auflösen (Fallback Ziel-PID, falls die
            // Seite z. B. wegen DataHandler-Fehler nicht angelegt wurde).
            $recordData['pid'] = $this->uidMap->get('pages', $oldPageId) ?? $targetPid;

            if (((int)($content['sys_language_uid'] ?? 0) > 0) && !empty($content['l18n_parent']) && $content['l18n_parent'] > 0) {
                $pOld = (int)$content['l18n_parent'];
                $recordData['l18n_parent'] = $this->uidMap->get('tt_content', $pOld) ?? self::NEW_CONTENT_PREFIX . $pOld;
                $this->l10nFixups['tt_content'][$oldContentUid] = $pOld;
            }

            if (!empty($config['import']['container_support']) && !empty($content['tx_container_parent'])
                && isset($exportedContentUids[(int)$content['tx_container_parent']])) {
                $cOld = (int)$content['tx_container_parent'];
                $recordData['tx_container_parent'] = $this->uidMap->get('tt_content', $cOld) ?? self::NEW_CONTENT_PREFIX . $cOld;
            }

            $datamap[self::NEW_CONTENT_PREFIX . $oldContentUid] = $recordData;
            $this->importStats['new']++;
        }
        return $datamap;
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
     * Ergänzt die Rollback-Map (nur für den ImportLog) um die vom Bundle-Import neu
     * platzierten sys_file-UIDs unter dem reservierten Schlüssel `_placed_files`. Der
     * Rollback-GC entfernt daraus später nur die danach unreferenzierten Dateien.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function withPlacedFiles(array $map): array
    {
        if ($this->placedFileUids !== []) {
            $map['_placed_files'] = array_values(array_unique($this->placedFileUids));
        }
        return $map;
    }

    /**
     * Schreibt den aktuellen Stand (bisher angelegte Records + platzierte Assets) ins
     * Protokoll. Wird vor dem ersten und nach jedem DataHandler-Batch aufgerufen, damit
     * ein harter Crash (SIGKILL/OOM) einen per undo rückrollbaren Teilimport hinterlässt.
     */
    private function checkpointImportLog(): void
    {
        if ($this->currentImportId === '') {
            return;
        }
        $this->importLogRepository->upsert(
            $this->currentImportId,
            $this->currentWorkspaceId,
            $this->currentSourceFile,
            $this->currentDelta,
            $this->withPlacedFiles($this->createdMap->toArray())
        );
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
     * Führt eine DataHandler-Schreiboperation in einer DB-Transaktion aus.
     *
     * Bündelt die zahlreichen Einzel-INSERTs eines Laufs (Record sowie sys_log
     * und sys_history) zu einem Commit – deutlich schneller beim Massenimport,
     * ohne Logging oder $dataHandler->errorLog anzutasten. Bei einem Abbruch
     * mitten im Lauf wird der Teilstand sauber zurückgerollt; der bestehende
     * Undo/Rollback bleibt für bereits committete Läufe gültig.
     *
     * Greift voll, solange die beteiligten Tabellen auf einer DB-Connection
     * liegen (TYPO3-Standard).
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
     * Behandelt einen abgebrochenen Import: schreibt ein Protokoll der bereits
     * angelegten Records und rollt diese – sofern aktiviert (Standard) – sofort
     * automatisch zurück, sodass kein halber Baum zurückbleibt.
     */
    private function handleAbortedImport(string $timestamp, string $jsonPath, array $options, \Throwable $e): void
    {
        $this->importLogRepository->upsert(
            $timestamp,
            (int)($options['workspaceId'] ?? 0),
            $jsonPath . ' [ABGEBROCHEN]',
            (bool)($options['deltaMode'] ?? false),
            $this->withPlacedFiles($this->createdMap->toArray())
        );

        if ($this->configurationService->isAutoRollbackOnFailure()) {
            try {
                // Auto-Rollback räumt die eigenen, gerade erst angelegten Teil-Records auf -> force.
                $this->rollbackService->runRollback($timestamp, true);
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
     * Warnt (ohne Abbruch) über Asset-Probleme auf dem Ziel: fehlende Dateien (deren
     * FAL-Referenzen beim Import übersprungen werden) und Dateien mit gleichem Identifier,
     * aber anderem Inhalt (sha256-Abgleich). Die bestehende Zieldatei wird bewusst nicht
     * überschrieben – der Datei-Transfer bleibt Operator-Sache.
     *
     * @param array<int, array{storage?:int, identifier?:string, sha256?:string|null}> $assets
     * @return int Anzahl der auf dem Ziel fehlenden Dateien (für Exit-Code ASSETS_MISSING)
     */
    private function warnOnAssetIntegrityIssues(array $assets): int
    {
        if ($assets === []) {
            return 0;
        }
        $mismatches = 0;
        $missing = 0;
        foreach ($this->assetManifestService->verifyAgainstTarget($assets) as $issue) {
            if ($issue['reason'] === 'missing') {
                $missing++;
                $this->logger->warning(sprintf(
                    'Asset fehlt auf dem Ziel: "%s" (Storage %d). Die FAL-Referenz wird übersprungen – '
                    . 'Datei vor dem Import übertragen (siehe impexpnl_assets.txt).',
                    $issue['identifier'],
                    $issue['storage']
                ));
                continue;
            }
            if ($issue['reason'] !== 'mismatch') {
                continue;
            }
            $mismatches++;
            $this->logger->warning(sprintf(
                'Asset-Integrität: "%s" (Storage %d) hat auf dem Ziel anderen Inhalt als im Export '
                . '(erwartet sha256 %s…, vorhanden %s…). Bestehende Datei bleibt unverändert.',
                $issue['identifier'],
                $issue['storage'],
                substr((string)$issue['expected'], 0, 12),
                substr((string)$issue['actual'], 0, 12)
            ));
        }
        if ($mismatches > 0) {
            $this->logger->warning(sprintf('Asset-Integrität: %d Datei(en) mit abweichendem Inhalt auf dem Ziel.', $mismatches));
        }
        if ($missing > 0) {
            $this->logger->warning(sprintf('Asset-Integrität: %d Datei(en) fehlen auf dem Ziel.', $missing));
        }
        return $missing;
    }

    /**
     * Asset-Preflight für den Dry-Run: Welche im Manifest gelisteten Dateien fehlen auf
     * dem Ziel (müssen noch übertragen werden) bzw. haben abweichenden Inhalt?
     *
     * @param array<int, array{storage?:int, identifier?:string, sha256?:string|null}> $assets
     * @return array{total:int, missing:list<string>, mismatch:list<string>}
     */
    private function buildAssetPreflight(array $assets): array
    {
        $missing = [];
        $mismatch = [];
        foreach ($this->assetManifestService->verifyAgainstTarget($assets) as $issue) {
            if ($issue['reason'] === 'missing') {
                $missing[] = $issue['identifier'];
            } elseif ($issue['reason'] === 'mismatch') {
                $mismatch[] = $issue['identifier'];
            }
        }
        return ['total' => count($assets), 'missing' => $missing, 'mismatch' => $mismatch];
    }

    /**
     * Wendet die konfigurierten URL-Ersetzungen (`import.rewrite_urls`) auf die
     * importierten Records an – bewusst getrennt vom t3://-Link-Rewriter, Default aus.
     *
     * @param array<string, array<int,int>> $uidMap
     * @param array<string, mixed> $config
     */
    private function rewriteAbsoluteUrls(array $uidMap, array $config, int $workspaceId): void
    {
        $cfg = $config['import']['rewrite_urls'] ?? [];
        if (empty($cfg['enabled']) || empty($cfg['rules']) || !is_array($cfg['rules'])) {
            return;
        }
        $fields = !empty($cfg['fields']) && is_array($cfg['fields'])
            ? array_values(array_map('strval', $cfg['fields']))
            : $this->configurationService->getLinkRewriteFields();

        $changed = $this->urlRewriteService->rewrite($uidMap, array_values($cfg['rules']), $fields, $workspaceId);
        if ($changed > 0) {
            $this->logger->info(sprintf('rewrite_urls: %d Feld(er) angepasst.', $changed));
        }
    }

    /**
     * Rollback-Basis: ausschließlich neu angelegte Records. Gematchte/aktualisierte
     * Bestands-Records werden bewusst NICHT aufgenommen, damit ein Rollback keine
     * vorbestehenden Daten löscht.
     *
     * @param array<string, array<int,int>> $uidArray vollständige UID-Map nach dem Import
     * @return array<string, array<int,int>>
     */
    /**
     * @param array<string, array<int,int>> $createdRegistry Nur NEU angelegte
     *        Registry-Records. Die volle $uidArray enthält im Delta-Modus auch
     *        gematchte Bestands-Records – die dürfen nicht in die Rollback-Basis,
     *        sonst würde ein Undo Bestandsdaten löschen.
     */
    private function buildRollbackMap(array $uidArray, array $createdRegistry = []): array
    {
        $rollback = $this->createdMap->toArray();
        foreach ($uidArray as $table => $entries) {
            // pages/tt_content stammen aus createdMap; sys_file referenziert Bestandsdateien.
            if (in_array($table, ['pages', 'tt_content', 'sys_file'], true)) {
                continue;
            }
            $rollback[$table] = $createdRegistry[$table] ?? $entries;
        }
        return $rollback;
    }

    /**
     * Führt einen einzelnen Batch über den DataHandler aus. Batch-Commit und
     * Checkpoint-Fortschreibung laufen in EINER Transaktion, damit ein Abbruch
     * dazwischen keine committeten Records ohne Undo-Eintrag hinterlässt.
     *
     * @param array<string|int, array<string,mixed>> $datamap
     */
    private function executeDataHandlerBatch(string $table, array $datamap, ?callable $onProgress, int $batchIndex, int $batchCount): void
    {
        if ($datamap === []) {
            // Leerer Batch (z. B. alle Records im Delta übersprungen): nichts zu
            // schreiben, aber Fortschritt trotzdem melden.
            if ($onProgress) {
                $onProgress("$table Batch $batchIndex/$batchCount", $batchIndex, $batchCount);
            }
            return;
        }

        $this->importLock->refresh();
        $prefix = $table === 'pages' ? self::NEW_PAGE_PREFIX : self::NEW_CONTENT_PREFIX;

        // Eltern-UIDs aus früheren Batches auflösen: ein neuer DataHandler kennt
        // nur die Platzhalter des aktuellen Batches. Seiten-interne NEW_PAGE_-Pids
        // bleiben als String, damit der DataHandler sie batchintern auflöst.
        if ($table === 'pages') {
            foreach ($datamap as &$record) {
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
        $dataHandler->start([$table => $datamap], []);

        $this->processInTransaction($table, function () use ($dataHandler, $table, $prefix): void {
            $dataHandler->process_datamap();
            foreach ($dataHandler->substNEWwithIDs as $placeholder => $newUid) {
                if (str_starts_with($placeholder, $prefix)) {
                    $oldUid = (int)str_replace($prefix, '', $placeholder);
                    $this->uidMap->set($table, $oldUid, (int)$newUid);
                    $this->createdMap->set($table, $oldUid, (int)$newUid);
                }
            }
            $this->checkpointImportLog();
        });

        $this->recordDataHandlerErrors($dataHandler, $table);

        if ($onProgress) {
            $onProgress("$table Batch $batchIndex/$batchCount", $batchIndex, $batchCount);
        }
    }

    // =========================================================================
    // HILFSMETHODEN
    // =========================================================================

    protected function loadAndValidateJson(string $jsonPath): ManifestReader
    {
        return $this->manifestLoader->load($jsonPath);
    }

    /**
     * Setzt l10n_parent (pages) bzw. l18n_parent (tt_content) übersetzter Records auf die
     * nun bekannten Ziel-UIDs der Default-Sprach-Records. Direkter DB-Update, da der
     * DataHandler den NEW_-Platzhalter in diesen Feldern nicht auflöst.
     *
     * @param array{pages: array<int,int>, tt_content: array<int,int>} $fixups  Quell-UID => Quell-Eltern-UID
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
