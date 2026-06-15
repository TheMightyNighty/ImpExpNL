<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Robbi\RobbiCopy\Event\ModifyImportDataEvent;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImportService
{
    private array $uidMap = ['pages' => [], 'tt_content' => []];
    private ?array $yamlConfigCache = null;

    /**
     * Felder die beim Import grundsätzlich ignoriert werden (Systemfelder).
     * Ergänzt wird dynamisch durch buildRecordData() anhand des tatsächlichen TCA.
     */
    private array $excludedFields = [
        'uid', 'pid', 'tstamp', 'crdate',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_move_id',
        't3_origuid', 'l10n_diffsource',
    ];

    /** Anzahl Records pro DataHandler-Batch (Parallele Verarbeitung) */
    private int $batchSize = 500;

    /** Cache: Bekannte Spalten pro Tabelle (v15-ready: nur TCA-bekannte Felder importieren) */
    private array $knownColumnsCache = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
        private readonly BootstrapService $bootstrapService,
        private readonly LinkRewriterService $linkRewriterService,
        private readonly FalResolverService $falResolverService,
        private readonly TableRegistryService $tableRegistry,
        private readonly YamlFileLoader $yamlFileLoader,
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
    public function runImport(string $jsonPath, int $targetPid, array $options = []): void
    {
        $dryRun = (bool)($options['dryRun'] ?? false);
        $workspaceId = (int)($options['workspaceId'] ?? 0);

        $this->bootstrapService->initializeBackendContext($workspaceId);
        $this->uidMap = ['pages' => [], 'tt_content' => []];

        if ($dryRun) {
            $importData = $this->loadAndValidateJson($jsonPath);
            $this->runDiffAnalysis($importData, (bool)($options['verbose'] ?? false));
            $this->logger->info('DRY-RUN BEENDET.');
            return;
        }

        $lockHandle = $this->acquireImportLock();
        try {
            $this->executeImport($jsonPath, $targetPid, $options);
        } finally {
            $this->releaseImportLock($lockHandle);
        }
    }

    protected function executeImport(string $jsonPath, int $targetPid, array $options): void
    {
        $workspaceId = (int)($options['workspaceId'] ?? 0);
        $deltaMode = (bool)($options['deltaMode'] ?? false);
        $conflictStrategy = $options['conflict'] ?? 'overwrite';
        $verbose = (bool)($options['verbose'] ?? false);
        $onProgress = $options['onProgress'] ?? null;
        $onConflictAsk = $options['onConflictAsk'] ?? null;

        $importData = $this->loadAndValidateJson($jsonPath);
        $config = $this->getYamlConfig();

        $existingPageMap = $this->findExistingRecordsByRemoteUid('pages', $importData['pages']);
        $existingContentMap = $this->findExistingRecordsByRemoteUid('tt_content', $importData['tt_content'] ?? []);

        $exportedPageUids = array_column($importData['pages'], 'uid');
        $exportedContentUids = array_column($importData['tt_content'] ?? [], 'uid');
        $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'conflict_skipped' => 0];

        // --- 1. SEITEN MAPPEN ---
        $pageDatamap = [];
        foreach ($importData['pages'] as $page) {
            $oldUid = (int)$page['uid'];
            $recordData = $this->buildRecordData($page, 'pages');
            $recordData['tx_robbicopy_remote_uid'] = $oldUid;

            if ($deltaMode && isset($existingPageMap[$oldUid])) {
                $existingUid = (int)$existingPageMap[$oldUid]['uid'];

                if ($this->isRecordIdentical($page, $existingPageMap[$oldUid])) {
                    $this->uidMap['pages'][$oldUid] = $existingUid;
                    $stats['skipped']++;
                    continue;
                }

                // Conflict-Check
                $conflict = $this->checkSingleConflict($page, $existingPageMap[$oldUid]);
                if ($conflict && $conflictStrategy === 'skip') {
                    $this->uidMap['pages'][$oldUid] = $existingUid;
                    $stats['conflict_skipped']++;
                    $this->logger->warning('Konflikt übersprungen: ' . $conflict);
                    continue;
                }
                if ($conflict && $conflictStrategy === 'ask' && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'pages', 'uid' => $existingUid])) {
                        $this->uidMap['pages'][$oldUid] = $existingUid;
                        $stats['conflict_skipped']++;
                        continue;
                    }
                }

                if ($verbose && $conflict) {
                    $this->logFieldDiff($page, $existingPageMap[$oldUid], 'pages');
                }

                $pageDatamap[$existingUid] = $recordData;
                $this->uidMap['pages'][$oldUid] = $existingUid;
                $stats['updated']++;
                continue;
            }

            $newIdString = 'NEW_PAGE_' . $oldUid;
            $oldPid = (int)$page['pid'];
            $recordData['pid'] = in_array($oldPid, $exportedPageUids, true) ? 'NEW_PAGE_' . $oldPid : $targetPid;

            if (((int)($page['sys_language_uid'] ?? 0) > 0) && !empty($page['l10n_parent']) && $page['l10n_parent'] > 0) {
                $pOld = (int)$page['l10n_parent'];
                $recordData['l10n_parent'] = $this->uidMap['pages'][$pOld] ?? 'NEW_PAGE_' . $pOld;
            }

            $pageDatamap[$newIdString] = $recordData;
            $stats['new']++;
        }

        // --- 2. INHALTE MAPPEN ---
        $contentDatamap = [];
        foreach ($importData['tt_content'] ?? [] as $content) {
            $oldContentUid = (int)$content['uid'];
            $oldPageId = (int)$content['pid'];
            if (!in_array($oldPageId, $exportedPageUids, true)) continue;

            $recordData = $this->buildRecordData($content, 'tt_content');
            $recordData['tx_robbicopy_remote_uid'] = $oldContentUid;

            if ($deltaMode && isset($existingContentMap[$oldContentUid])) {
                $existingUid = (int)$existingContentMap[$oldContentUid]['uid'];

                if ($this->isRecordIdentical($content, $existingContentMap[$oldContentUid])) {
                    $this->uidMap['tt_content'][$oldContentUid] = $existingUid;
                    $stats['skipped']++;
                    continue;
                }

                $conflict = $this->checkSingleConflict($content, $existingContentMap[$oldContentUid]);
                if ($conflict && $conflictStrategy === 'skip') {
                    $this->uidMap['tt_content'][$oldContentUid] = $existingUid;
                    $stats['conflict_skipped']++;
                    continue;
                }
                if ($conflict && $conflictStrategy === 'ask' && $onConflictAsk) {
                    if (!$onConflictAsk(['message' => $conflict, 'table' => 'tt_content', 'uid' => $existingUid])) {
                        $this->uidMap['tt_content'][$oldContentUid] = $existingUid;
                        $stats['conflict_skipped']++;
                        continue;
                    }
                }

                $contentDatamap[$existingUid] = $recordData;
                $this->uidMap['tt_content'][$oldContentUid] = $existingUid;
                $stats['updated']++;
                continue;
            }

            $newId = 'NEW_CONTENT_' . $oldContentUid;
            $recordData['pid'] = $this->uidMap['pages'][$oldPageId] ?? 'NEW_PAGE_' . $oldPageId;

            if (((int)($content['sys_language_uid'] ?? 0) > 0) && !empty($content['l18n_parent']) && $content['l18n_parent'] > 0) {
                $pOld = (int)$content['l18n_parent'];
                $recordData['l18n_parent'] = $this->uidMap['tt_content'][$pOld] ?? 'NEW_CONTENT_' . $pOld;
            }

            if (!empty($config['import']['container_support']) && !empty($content['tx_container_parent'])
                && in_array((int)$content['tx_container_parent'], $exportedContentUids, true)) {
                $cOld = (int)$content['tx_container_parent'];
                $recordData['tx_container_parent'] = $this->uidMap['tt_content'][$cOld] ?? 'NEW_CONTENT_' . $cOld;
            }

            $contentDatamap[$newId] = $recordData;
            $stats['new']++;
        }

        // --- 3. CHUNKED DATAHANDLER (Parallele Verarbeitung) ---
        // Seiten zuerst (damit PIDs für Content aufgelöst werden können)
        $this->executeBatchedDataHandler('pages', $pageDatamap, $onProgress);

        // Content-PIDs nach Seiten-Import auflösen (NEW_PAGE_ → echte UIDs)
        foreach ($contentDatamap as $newId => &$recordData) {
            if (!isset($recordData['pid'])) {
                continue;
            }
            if (is_string($recordData['pid']) && str_starts_with($recordData['pid'], 'NEW_PAGE_')) {
                $oldPageUid = (int)str_replace('NEW_PAGE_', '', $recordData['pid']);
                $recordData['pid'] = (int)($this->uidMap['pages'][$oldPageUid] ?? $targetPid);
            }
            if (!is_int($recordData['pid'])) {
                $recordData['pid'] = (int)$recordData['pid'];
            }
        }
        unset($recordData);

        // Content danach
        $this->executeBatchedDataHandler('tt_content', $contentDatamap, $onProgress);

        // --- 4. Multi-Site: Slugs anpassen ---
        if (!empty($importData['_site_config'])) {
            $this->adjustSlugsForTargetSite($this->uidMap);
        }

        // --- 5. FAL ---
        if (!empty($config['import']['include']['file_references']) && !empty($importData['sys_file_reference'])) {
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $this->falResolverService->importReferences($importData['sys_file_reference'], $this->uidMap, $dh, ['storageId' => 1, 'upsert' => $deltaMode]);
        }

        // --- 6. IRRE ---
        if (!empty($importData['irre_relations'])) {
            $this->importInlineRelations($importData['irre_relations']);
        }

        // --- 7. Table-Registry ---
        $this->tableRegistry->importRegisteredTables($importData, $this->uidMap);

        // --- 8. Links ---
        $this->linkRewriterService->rewriteLinks($this->uidMap, $workspaceId);

        // --- 9. Events ---
        $this->eventDispatcher->dispatch(new ModifyImportDataEvent($importData, $this->uidMap));

        // --- 10. Protokoll ---
        $timestamp = date('Ymd_His');
        $this->saveImportLog($timestamp, $workspaceId, $jsonPath, $deltaMode);
        $this->writeTransactionLog($timestamp, $workspaceId, $jsonPath, $stats, $deltaMode);

        $this->logger->info('Import abgeschlossen', array_merge($stats, ['importId' => $timestamp]));
    }

    /**
     * Chunked DataHandler: Verarbeitet Records in Batches statt alles auf einmal.
     * Reduziert Memory-Peak bei großen Bäumen (10.000+ Records).
     */
    private function executeBatchedDataHandler(string $table, array $datamap, ?callable $onProgress): void
    {
        if (empty($datamap)) return;

        $batches = array_chunk($datamap, $this->batchSize, true);
        $batchCount = count($batches);
        $batchIndex = 0;

        foreach ($batches as $batch) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => $batch], []);
            $dataHandler->process_datamap();

            if (!empty($dataHandler->errorLog)) {
                foreach ($dataHandler->errorLog as $error) {
                    $this->logger->error("DataHandler ($table): $error");
                }
            }

            // UID-Mapping
            $prefix = $table === 'pages' ? 'NEW_PAGE_' : 'NEW_CONTENT_';
            foreach ($dataHandler->substNEWwithIDs as $placeholder => $newUid) {
                if (str_starts_with($placeholder, $prefix)) {
                    $oldUid = (int)str_replace($prefix, '', $placeholder);
                    $this->uidMap[$table][$oldUid] = (int)$newUid;
                }
            }

            $batchIndex++;
            if ($onProgress) {
                $onProgress("$table Batch $batchIndex/$batchCount", $batchIndex, $batchCount);
            }
        }
    }

    /**
     * Multi-Site: Slug-Felder regenerieren für das Zielsystem.
     */
    private function adjustSlugsForTargetSite(array $uidMap): void
    {
        if (empty($uidMap['pages'])) return;

        try {
            $slugHelper = GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\DataHandling\SlugHelper::class,
                'pages', 'slug',
                $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? []
            );
        } catch (\Exception $e) {
            return; // SlugHelper nicht verfügbar (v12 ältere Patch-Versionen)
        }

        $datamap = [];
        foreach ($uidMap['pages'] as $oldUid => $newUid) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $page = $qb->select('*')->from('pages')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newUid, Connection::PARAM_INT)))
                ->executeQuery()->fetchAssociative();

            if (!$page) continue;

            try {
                $newSlug = $slugHelper->generate($page, (int)$page['pid']);
                if ($newSlug !== ($page['slug'] ?? '')) {
                    $datamap['pages'][$newUid] = ['slug' => $newSlug];
                }
            } catch (\Exception $e) {
                // Slug-Generierung fehlgeschlagen → behalten
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

    protected function loadAndValidateJson(string $jsonPath): array
    {
        if (!file_exists($jsonPath)) throw new \RuntimeException("Datei nicht gefunden: $jsonPath");

        // OWASP A01: Sicherstellen, dass die Datei nicht über Symlinks/Traversal auf sensible Bereiche zeigt
        $realPath = realpath($jsonPath);
        if ($realPath === false || !str_ends_with($realPath, '.json')) {
            throw new \RuntimeException("Ungültiger Dateipfad oder keine JSON-Datei: $jsonPath");
        }

        $data = json_decode(file_get_contents($realPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new \RuntimeException('JSON-Fehler: ' . json_last_error_msg());
        if (empty($data['pages']) || !is_array($data['pages'])) throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');

        if (!empty($data['_meta']['checksum'])) {
            $actual = hash('sha256', json_encode($data['pages']) . json_encode($data['tt_content'] ?? []));
            if ($actual !== $data['_meta']['checksum']) {
                throw new \RuntimeException('Integritätsprüfung fehlgeschlagen: JSON wurde verändert.');
            }
        }
        return $data;
    }

    private function findExistingRecordsByRemoteUid(string $table, array $records): array
    {
        $uids = array_column($records, 'uid');
        if (empty($uids)) return [];
        $map = [];
        foreach (array_chunk($uids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $rows = $qb->select('*')->from($table)
                ->where($qb->expr()->in('tx_robbicopy_remote_uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) $map[(int)$r['tx_robbicopy_remote_uid']] = $r;
        }
        return $map;
    }

    protected function isRecordIdentical(array $import, array $existing): bool
    {
        $ignore = array_merge($this->excludedFields, ['tx_robbicopy_remote_uid', 'sorting']);
        foreach ($import as $field => $value) {
            if (in_array($field, $ignore, true)) continue;
            if (!array_key_exists($field, $existing)) continue;
            if ((string)$value !== (string)$existing[$field]) return false;
        }
        return true;
    }

    protected function checkSingleConflict(array $importRecord, array $existingRecord): ?string
    {
        $exportTs = (int)($importRecord['tstamp'] ?? 0);
        $localTs = (int)($existingRecord['tstamp'] ?? 0);
        if ($localTs > $exportTs && !$this->isRecordIdentical($importRecord, $existingRecord)) {
            return sprintf('uid=%d ("%s"): Lokal %s, Export %s',
                $existingRecord['uid'], $existingRecord['title'] ?? '',
                date('d.m.Y H:i', $localTs), date('d.m.Y H:i', $exportTs));
        }
        return null;
    }

    /**
     * --verbose: Zeigt welche Felder sich unterscheiden.
     */
    private function logFieldDiff(array $import, array $existing, string $table): void
    {
        $ignore = array_merge($this->excludedFields, ['tx_robbicopy_remote_uid', 'sorting']);
        $diffs = [];
        foreach ($import as $field => $value) {
            if (in_array($field, $ignore, true)) continue;
            if (!array_key_exists($field, $existing)) continue;
            if ((string)$value !== (string)$existing[$field]) {
                $oldVal = mb_substr((string)$existing[$field], 0, 80);
                $newVal = mb_substr((string)$value, 0, 80);
                $diffs[] = "    $field: \"$oldVal\" → \"$newVal\"";
            }
        }
        if (!empty($diffs)) {
            $this->logger->info("Feld-Diff $table uid=" . ($existing['uid'] ?? '?') . ":\n" . implode("\n", $diffs));
        }
    }

    /**
     * Baut Record-Daten für den DataHandler.
     * v15-ready: Filtert dynamisch anhand der tatsächlichen DB-Spalten,
     * sodass entfernte Felder (z.B. cruser_id) keine DataHandler-Warnings erzeugen.
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
            $columns = $schemaManager->listTableColumns($table);
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

    private function importInlineRelations(array $irreRelations): void
    {
        if (empty($irreRelations)) return;
        $datamap = [];
        $count = 0;
        foreach ($irreRelations as $rel) {
            $table = $rel['table'] ?? '';
            $ff = $rel['foreign_field'] ?? '';
            $rec = $rel['record'] ?? [];
            if (empty($table) || empty($ff) || empty($rec)) continue;
            $oldParent = (int)($rec[$ff] ?? 0);
            $newParent = $this->uidMap['tt_content'][$oldParent] ?? null;
            if ($newParent === null) continue;

            $child = [];
            foreach ($rec as $f => $v) {
                if (!in_array($f, $this->excludedFields, true)) $child[$f] = $v;
            }
            $child[$ff] = $newParent;
            $child['pid'] = $this->uidMap['pages'][$rec['pid'] ?? 0] ?? ($rec['pid'] ?? 0);
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

    protected function runDiffAnalysis(array $importData, bool $verbose): void
    {
        $this->logger->info('=== DIFFERENZ-ANALYSE ===');
        $eP = $this->findExistingRecordsByRemoteUid('pages', $importData['pages']);
        $nP = $uP = $iP = 0;
        foreach ($importData['pages'] as $p) {
            $id = (int)$p['uid'];
            if (!isset($eP[$id])) { $nP++; $this->logger->info('<fg=green>+ NEU</>        | ' . ($p['title'] ?? '')); }
            elseif (!$this->isRecordIdentical($p, $eP[$id])) {
                $uP++;
                $this->logger->info('<fg=yellow>~ GEÄNDERT</>   | ' . ($p['title'] ?? ''));
                if ($verbose) $this->logFieldDiff($p, $eP[$id], 'pages');
            }
            else { $iP++; $this->logger->info('<fg=blue>= IDENTISCH</>  | ' . ($p['title'] ?? '')); }
        }

        $eC = $this->findExistingRecordsByRemoteUid('tt_content', $importData['tt_content'] ?? []);
        $nC = $uC = $iC = 0;
        foreach ($importData['tt_content'] ?? [] as $c) {
            $id = (int)$c['uid'];
            if (!isset($eC[$id])) $nC++;
            elseif (!$this->isRecordIdentical($c, $eC[$id])) { $uC++; if ($verbose) $this->logFieldDiff($c, $eC[$id], 'tt_content'); }
            else $iC++;
        }

        $this->logger->info(sprintf('Seiten:  %d neu, %d geändert, %d identisch.', $nP, $uP, $iP));
        $this->logger->info(sprintf('Inhalte: %d neu, %d geändert, %d identisch.', $nC, $uC, $iC));
        if ($iP + $iC > 0) $this->logger->info('Tipp: Mit --delta werden identische Records übersprungen.');
    }

    // =========================================================================
    // LOCK
    // =========================================================================

    private function acquireImportLock(): mixed
    {
        $lockFile = Environment::getVarPath() . '/robbicopy_import.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $h = fopen($lockFile, 'c+');
        if (!$h) throw new \RuntimeException('Lock nicht öffenbar: ' . $lockFile);
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); throw new \RuntimeException('Ein anderer Import läuft. Lock: ' . $lockFile); }
        ftruncate($h, 0);
        fwrite($h, json_encode(['pid' => getmypid(), 'started' => date('c')]));
        fflush($h);
        return $h;
    }

    private function releaseImportLock($h): void
    {
        if (is_resource($h)) { flock($h, LOCK_UN); fclose($h); }
    }

    // =========================================================================
    // PROTOKOLL
    // =========================================================================

    private function saveImportLog(string $id, int $ws, string $file, bool $delta): void
    {
        $this->connectionPool->getConnectionForTable('tx_robbicopy_import_log')->insert('tx_robbicopy_import_log', [
            'import_id' => $id, 'tstamp' => time(), 'workspace_id' => $ws,
            'uid_map' => json_encode($this->uidMap), 'source_file' => $file, 'delta_mode' => $delta ? 1 : 0,
        ]);
    }

    private function writeTransactionLog(string $id, int $ws, string $file, array $stats, bool $delta): void
    {
        $logDir = Environment::getVarPath() . '/log';
        if (!is_dir($logDir)) mkdir($logDir, 0775, true);
        $mode = $delta ? 'DELTA' : 'VOLL';
        $lines = [str_repeat('=', 72), sprintf('[%s] %s-IMPORT %s', date('Y-m-d H:i:s'), $mode, $id), str_repeat('-', 72),
            sprintf('Quelldatei: %s | Workspace: %d', $file, $ws),
            sprintf('Neu: %d | Aktualisiert: %d | Übersprungen: %d | Konflikte übersprungen: %d', $stats['new'], $stats['updated'], $stats['skipped'], $stats['conflict_skipped'] ?? 0), ''];
        foreach (['pages', 'tt_content'] as $t) {
            if (!empty($this->uidMap[$t])) {
                $lines[] = "$t:";
                foreach ($this->uidMap[$t] as $o => $n) $lines[] = "  $o → $n";
            }
        }
        $lines[] = '';
        $lines[] = "Undo: vendor/bin/typo3 robbicopy:undo $id";
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        file_put_contents($logDir . '/robbicopy_transactions.log', implode("\n", $lines), FILE_APPEND | LOCK_EX);
    }

    private function getYamlConfig(): array
    {
        if ($this->yamlConfigCache !== null) return $this->yamlConfigCache;
        try { $this->yamlConfigCache = $this->yamlFileLoader->load('EXT:robbi_copy/robbi_copy.yaml'); }
        catch (\Exception $e) { $this->yamlConfigCache = []; }
        return $this->yamlConfigCache;
    }
}
