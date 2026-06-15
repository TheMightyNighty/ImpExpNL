<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Robbi\RobbiCopy\Domain\ConflictStrategy;
use Robbi\RobbiCopy\Domain\ExportManifest;
use Robbi\RobbiCopy\Domain\SystemFields;
use Robbi\RobbiCopy\Domain\UidMap;
use Robbi\RobbiCopy\Event\ModifyImportDataEvent;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImportService
{
    /** DataHandler-Platzhalter-Präfixe für neu anzulegende Records. */
    private const NEW_PAGE_PREFIX = 'NEW_PAGE_';
    private const NEW_CONTENT_PREFIX = 'NEW_CONTENT_';

    private UidMap $uidMap;

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

        $this->bootstrapService->initializeBackendContext($workspaceId);
        $this->uidMap = new UidMap();

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
            // Wurden bereits Records angelegt, muss ein Rollback-Protokoll existieren,
            // damit die Teil-Daten per robbicopy:undo entfernbar bleiben.
            if ($this->hasMappedRecords()) {
                $this->importLogRepository->save($timestamp, (int)($options['workspaceId'] ?? 0), $jsonPath . ' [ABGEBROCHEN]', (bool)($options['deltaMode'] ?? false), $this->uidMap->toArray());
                $this->logger->error('Import abgebrochen – Notfall-Protokoll geschrieben (Rollback via robbicopy:undo ' . $timestamp . ' möglich).', [
                    'importId' => $timestamp,
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        $this->importLogRepository->save($timestamp, $workspaceId, $jsonPath, $deltaMode, $this->uidMap->toArray());
        $this->writeTransactionLog($timestamp, $workspaceId, $jsonPath, $stats, $deltaMode);

        $this->logger->info('Import abgeschlossen', array_merge($stats, ['importId' => $timestamp]));

        return ['importId' => $timestamp, 'stats' => $stats];
    }

    /**
     * Führt die eigentlichen Schreiboperationen aus und liefert die Statistik zurück.
     * Die Protokollierung erfolgt in executeImport() (auch im Fehlerfall).
     *
     * @return array{new:int, updated:int, skipped:int, conflict_skipped:int}
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
        $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'conflict_skipped' => 0];

        $pageDatamap = [];
        foreach ($pages as $page) {
            $oldUid = (int)$page['uid'];
            $recordData = $this->buildRecordData($page, 'pages');
            $recordData['tx_robbicopy_remote_uid'] = $oldUid;

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
            $recordData['tx_robbicopy_remote_uid'] = $oldContentUid;

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
            $this->falResolverService->importReferences($fileReferences, $uidArray, $dh, ['storageId' => 1, 'upsert' => $deltaMode]);
        }

        if (!empty($manifest->getIrreRelations())) {
            $this->importInlineRelations($manifest->getIrreRelations(), $uidArray);
        }

        $rawData = $manifest->toArray();
        $this->tableRegistry->importRegisteredTables($rawData, $uidArray);
        $this->linkRewriterService->rewriteLinks($uidArray, $workspaceId);

        $this->uidMap = UidMap::fromArray($uidArray);
        $this->eventDispatcher->dispatch(new ModifyImportDataEvent($rawData, $uidArray));

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
        return !$this->uidMap->isEmpty();
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

        foreach ($batches as $batch) {
            $this->importLock->refresh();

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => $batch], []);
            $dataHandler->process_datamap();

            if (!empty($dataHandler->errorLog)) {
                foreach ($dataHandler->errorLog as $error) {
                    $this->logger->error("DataHandler ($table): $error");
                }
            }

            $prefix = $table === 'pages' ? self::NEW_PAGE_PREFIX : self::NEW_CONTENT_PREFIX;
            foreach ($dataHandler->substNEWwithIDs as $placeholder => $newUid) {
                if (str_starts_with($placeholder, $prefix)) {
                    $oldUid = (int)str_replace($prefix, '', $placeholder);
                    $this->uidMap->set($table, $oldUid, (int)$newUid);
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
                    $newSlug = $slugHelper->generate($page, (int)$page['pid']);
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
            $dh->process_datamap();
            $this->logger->info('Slugs angepasst', ['count' => count($datamap['pages'])]);
        }
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
        if ($realPath === false || !str_ends_with($realPath, '.json')) {
            throw new \RuntimeException("Ungültiger Dateipfad oder keine JSON-Datei: $jsonPath");
        }

        $projectPath = Environment::getProjectPath();
        if ($projectPath !== '' && !str_starts_with($realPath, $projectPath)) {
            throw new \RuntimeException("Importdatei liegt außerhalb des Projektverzeichnisses: $jsonPath");
        }

        $data = json_decode(file_get_contents($realPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON-Fehler: ' . json_last_error_msg());
        }

        $manifest = ExportManifest::fromArray(is_array($data) ? $data : []);
        if (!$manifest->hasPages()) {
            throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');
        }

        $checksum = $manifest->getChecksum();
        if ($checksum !== null && !$this->integrityService->verify($manifest->toArray(), $checksum)) {
            throw new \RuntimeException(
                'Integritätsprüfung fehlgeschlagen: JSON wurde verändert oder die Signatur kann ohne '
                . 'konfigurierten Schlüssel (ROBBICOPY_SIGNING_KEY) nicht verifiziert werden.'
            );
        }
        return $manifest;
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
                ->where($qb->expr()->in('tx_robbicopy_remote_uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $map[(int)$r['tx_robbicopy_remote_uid']] = $r;
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
        $data = [];
        foreach ($source as $field => $value) {
            if (in_array($field, $this->excludedFields, true)) {
                continue;
            }
            // Wenn Spalten-Info vorhanden: nur tatsächlich existierende Felder übernehmen
            if (!empty($knownColumns) && !isset($knownColumns[$field])) {
                continue;
            }
            $data[$field] = $value;
        }
        return $data;
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
            $child['pid'] = $uidMap['pages'][$rec['pid'] ?? 0] ?? ($rec['pid'] ?? 0);
            $datamap[$table]['NEW_IRRE_' . $table . '_' . ($rec['uid'] ?? $count)] = $child;
            $count++;
        }
        if (!empty($datamap)) {
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start($datamap, []);
            $dh->process_datamap();
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
        $lines[] = "Undo: vendor/bin/typo3 robbicopy:undo $id";
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        file_put_contents($logDir . '/robbicopy_transactions.log', implode("\n", $lines), FILE_APPEND | LOCK_EX);
    }
}
