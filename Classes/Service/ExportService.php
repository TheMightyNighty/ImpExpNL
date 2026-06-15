<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Robbi\RobbiCopy\Event\ModifyExportDataEvent;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExportService
{
    private ?array $yamlConfigCache = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly BootstrapService $bootstrapService,
        private readonly TableRegistryService $tableRegistry,
        private readonly YamlFileLoader $yamlFileLoader,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Export-Optionen:
     *  depth: int           - Maximale Tiefe (0 = unbegrenzt)
     *  includeHidden: bool  - Auch versteckte/deaktivierte
     *  pages: int[]         - Nur bestimmte PIDs (Einzelseiten, ohne Kinder)
     *  excludePages: int[]  - PIDs ausschließen
     *  since: string        - Nur Records geändert seit (Y-m-d oder Timestamp)
     *  contentTypes: string[] - Nur bestimmte CTypes
     *  onProgress: callable - Fortschritts-Callback
     */
    public function exportTree(int $startPid, array $options = []): string
    {
        $this->bootstrapService->initializeBackendContext();
        $finalData = $this->collectAndDispatch($startPid, $options);
        return json_encode($finalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Exportiert einen Seitenbaum als JSON-Datei inkl. Assets-Liste und Broken-Links-Report.
     */
    public function runExport(int $startPid, string $filePath, array $options = []): void
    {
        $this->bootstrapService->initializeBackendContext();
        $finalData = $this->collectAndDispatch($startPid, $options);

        $jsonContent = json_encode($finalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $jsonContent);

        $baseDir = dirname($filePath);
        $this->writeAssetsList($finalData, $baseDir);
        $this->writeBrokenLinksReport($finalData, $baseDir);

        // Optional: CSV-Format zusätzlich
        if (!empty($options['csv'])) {
            $this->writeCsvExport($finalData, $baseDir);
        }

        $this->logger->info('Export abgeschlossen', [
            'pages' => count($finalData['pages'] ?? []),
            'tt_content' => count($finalData['tt_content'] ?? []),
            'file' => $filePath,
        ]);
    }

    private function collectAndDispatch(int $startPid, array $options): array
    {
        $data = $this->collectExportData($startPid, $options);

        $event = new ModifyExportDataEvent($data);
        $this->eventDispatcher->dispatch($event);
        $finalData = $event->getExportData();

        $finalData['_meta'] = $this->buildExportMeta($startPid, $finalData, $options);
        return $finalData;
    }

    protected function collectExportData(int $startPid, array $options): array
    {
        $depth = (int)($options['depth'] ?? 0);
        $includeHidden = (bool)($options['includeHidden'] ?? false);
        $explicitPages = $options['pages'] ?? [];
        $excludePages = $options['excludePages'] ?? [];
        $sinceTimestamp = $this->parseSince($options['since'] ?? null);
        $contentTypes = $options['contentTypes'] ?? [];
        $onProgress = $options['onProgress'] ?? null;

        $data = ['pages' => [], 'tt_content' => [], 'sys_file_reference' => [], 'irre_relations' => []];

        // Selektiver Export: Einzelseiten oder Baumexport
        if (!empty($explicitPages)) {
            $pageUids = $this->collectExplicitPages($explicitPages, $data['pages'], $includeHidden);
        } else {
            $pageUids = $this->collectPageTree($startPid, $data['pages'], $includeHidden, $depth, 0);
        }

        // Exclude-Filter
        if (!empty($excludePages)) {
            $pageUids = array_diff($pageUids, $excludePages);
            $data['pages'] = array_filter($data['pages'], fn($p) => !in_array((int)$p['uid'], $excludePages, true));
            $data['pages'] = array_values($data['pages']);
        }

        if (empty($pageUids)) {
            $this->logger->warning('Keine Seiten gefunden');
            return $data;
        }

        if ($onProgress) $onProgress('Seiten gesammelt', count($pageUids), 0);

        // Inhalte laden
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $this->applyRestrictions($qb, $includeHidden);

            $where = [$qb->expr()->in('pid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))];

            // Zeitfilter
            if ($sinceTimestamp > 0) {
                $where[] = $qb->expr()->gte('tstamp', $qb->createNamedParameter($sinceTimestamp, Connection::PARAM_INT));
            }

            // CType-Filter
            if (!empty($contentTypes)) {
                $where[] = $qb->expr()->in('CType', $qb->createNamedParameter($contentTypes, Connection::PARAM_STR_ARRAY));
            }

            $contents = $qb->select('*')->from('tt_content')
                ->where(...$where)
                ->orderBy('sorting', 'ASC')
                ->executeQuery()->fetchAllAssociative();

            $data['tt_content'] = array_merge($data['tt_content'], $contents);
        }

        if ($onProgress) $onProgress('Inhalte geladen', count($data['tt_content']), 0);

        // Multi-Site: Site-Konfiguration mit exportieren
        $data['_site_config'] = $this->exportSiteConfig($pageUids);

        // IRRE
        $data['irre_relations'] = $this->exportInlineRelations($data['tt_content']);

        // FAL
        $config = $this->getYamlConfig();
        if (!empty($config['export']['include']['file_references'])) {
            $data['sys_file_reference'] = $this->exportFileReferences($pageUids, $data['tt_content']);
        }

        // Table-Registry
        $contentUids = array_column($data['tt_content'], 'uid');
        $registryData = $this->tableRegistry->exportRegisteredTables($pageUids, $contentUids);
        $data = array_merge($data, $registryData);

        if ($onProgress) $onProgress('Registry + FAL exportiert', 0, 0);

        $this->checkDependencies($pageUids);
        return $data;
    }

    /**
     * Selektiver Export: Nur explizit angegebene PIDs (ohne Rekursion).
     */
    private function collectExplicitPages(array $pids, array &$pagesData, bool $includeHidden): array
    {
        $collectedUids = [];
        foreach (array_chunk($pids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $this->applyRestrictions($qb, $includeHidden);
            $pages = $qb->select('*')->from('pages')
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->orderBy('sorting', 'ASC')
                ->executeQuery()->fetchAllAssociative();

            foreach ($pages as $page) {
                $pagesData[] = $page;
                if ((int)$page['sys_language_uid'] === 0) {
                    $collectedUids[] = (int)$page['uid'];
                }
            }

            // Übersetzungen dazu
            $qb2 = $this->connectionPool->getQueryBuilderForTable('pages');
            $this->applyRestrictions($qb2, $includeHidden);
            $translations = $qb2->select('*')->from('pages')
                ->where($qb2->expr()->in('l10n_parent', $qb2->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($translations as $t) $pagesData[] = $t;
        }
        return $collectedUids;
    }

    protected function collectPageTree(int $pid, array &$pagesData, bool $includeHidden, int $maxDepth, int $currentDepth): array
    {
        $collectedUids = [];
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $this->applyRestrictions($qb, $includeHidden);

        $pages = $qb->select('*')->from('pages')
            ->where($qb->expr()->or(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pid, Connection::PARAM_INT)),
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pid, Connection::PARAM_INT))
            ))
            ->orderBy('sorting', 'ASC')
            ->executeQuery()->fetchAllAssociative();

        foreach ($pages as $page) {
            $pagesData[] = $page;
            if ((int)$page['sys_language_uid'] === 0) {
                $collectedUids[] = (int)$page['uid'];
                if ($maxDepth > 0 && $currentDepth >= $maxDepth) continue;

                $qbC = $this->connectionPool->getQueryBuilderForTable('pages');
                $this->applyRestrictions($qbC, $includeHidden);
                $children = $qbC->select('uid')->from('pages')
                    ->where($qbC->expr()->eq('pid', $qbC->createNamedParameter($page['uid'], Connection::PARAM_INT)))
                    ->orderBy('sorting', 'ASC')
                    ->executeQuery()->fetchAllAssociative();

                foreach ($children as $child) {
                    $childUids = $this->collectPageTree((int)$child['uid'], $pagesData, $includeHidden, $maxDepth, $currentDepth + 1);
                    $collectedUids = array_merge($collectedUids, $childUids);
                }
            }
        }
        return $collectedUids;
    }

    /**
     * Multi-Site: Site-Identifier und Basis-URL der exportierten Seiten speichern.
     */
    private function exportSiteConfig(array $pageUids): array
    {
        try {
            $sites = [];
            foreach ($pageUids as $uid) {
                try {
                    $site = $this->siteFinder->getSiteByPageId($uid);
                    $identifier = $site->getIdentifier();
                    if (!isset($sites[$identifier])) {
                        $sites[$identifier] = [
                            'identifier' => $identifier,
                            'base' => (string)$site->getBase(),
                            'rootPageId' => $site->getRootPageId(),
                            'languages' => array_map(fn($l) => [
                                'languageId' => $l->getLanguageId(),
                                'title' => $l->getTitle(),
                                'locale' => (string)$l->getLocale(),
                                'base' => (string)$l->getBase(),
                            ], $site->getLanguages()),
                        ];
                    }
                } catch (\Exception $e) {
                    // Seite gehört zu keiner Site → OK
                }
            }
            return array_values($sites);
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function applyRestrictions($qb, bool $includeHidden): void
    {
        if ($includeHidden) {
            $qb->getRestrictions()->removeAll()->add(
                GeneralUtility::makeInstance(DeletedRestriction::class)
            );
        } else {
            $qb->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction::class));
        }
    }

    private function exportInlineRelations(array $contentRecords): array
    {
        if (empty($contentRecords)) return [];
        $relations = [];
        $contentUids = array_column($contentRecords, 'uid');
        $tca = $GLOBALS['TCA']['tt_content']['columns'] ?? [];

        foreach ($tca as $fieldName => $fieldConfig) {
            $config = $fieldConfig['config'] ?? [];
            if (($config['type'] ?? '') !== 'inline' || empty($config['foreign_table'])) continue;

            $foreignTable = $config['foreign_table'];
            $foreignField = $config['foreign_field'] ?? 'parentid';

            foreach (array_chunk($contentUids, 1000) as $chunk) {
                try {
                    $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
                    $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                    $children = $qb->select('*')->from($foreignTable)
                        ->where($qb->expr()->in($foreignField, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                        ->orderBy('sorting', 'ASC')
                        ->executeQuery()->fetchAllAssociative();

                    foreach ($children as $child) {
                        $relations[] = ['table' => $foreignTable, 'foreign_field' => $foreignField, 'parent_field' => $fieldName, 'record' => $child];
                    }
                } catch (\Exception $e) {
                    // Tabelle nicht verfügbar
                }
            }
        }
        return $relations;
    }

    protected function buildExportMeta(int $startPid, array $data, array $options): array
    {
        $pJson = json_encode($data['pages'] ?? []);
        $cJson = json_encode($data['tt_content'] ?? []);
        $typo3Version = class_exists(Typo3Version::class) ? GeneralUtility::makeInstance(Typo3Version::class)->getVersion() : 'unknown';

        return [
            'export_version' => '4.14.0',
            'export_date' => date('c'),
            'typo3_version' => $typo3Version,
            'php_version' => PHP_VERSION,
            'source_pid' => $startPid,
            'source_host' => gethostname() ?: 'unknown',
            'filters' => array_filter([
                'depth' => $options['depth'] ?? 0,
                'pages' => $options['pages'] ?? null,
                'excludePages' => $options['excludePages'] ?? null,
                'since' => $options['since'] ?? null,
                'contentTypes' => $options['contentTypes'] ?? null,
            ]),
            'record_counts' => [
                'pages' => count($data['pages'] ?? []),
                'tt_content' => count($data['tt_content'] ?? []),
                'sys_file_reference' => count($data['sys_file_reference'] ?? []),
                'irre_relations' => count($data['irre_relations'] ?? []),
            ],
            'checksum' => hash('sha256', $pJson . $cJson),
        ];
    }

    /**
     * CSV-Export: Flache Tabellenübersicht für Tabellenvergleich.
     */
    private function writeCsvExport(array $data, string $baseDir): void
    {
        foreach (['pages', 'tt_content'] as $table) {
            $records = $data[$table] ?? [];
            if (empty($records)) continue;

            $file = $baseDir . '/robbicopy_' . $table . '.csv';
            $fp = fopen($file, 'w');
            fputcsv($fp, array_keys($records[0]));
            foreach ($records as $row) {
                fputcsv($fp, array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 500) : $v, $row));
            }
            fclose($fp);
        }
        $this->logger->info('CSV-Export geschrieben', ['dir' => $baseDir]);
    }

    protected function parseSince(?string $since): int
    {
        if (empty($since)) return 0;
        if (is_numeric($since)) return (int)$since;
        $ts = strtotime($since);
        return $ts !== false ? $ts : 0;
    }

    // --- FAL, Assets, BrokenLinks, Dependencies, YAML (kompakt) ---

    protected function exportFileReferences(array $pageUids, array $contentRecords): array
    {
        $refs = [];
        $cUids = array_column($contentRecords, 'uid');
        foreach (['pages' => $pageUids, 'tt_content' => $cUids] as $t => $uids) {
            if (empty($uids)) continue;
            foreach (array_chunk($uids, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
                $r = $qb->select('r.*', 'f.identifier', 'f.storage')
                    ->from('sys_file_reference', 'r')
                    ->join('r', 'sys_file', 'f', $qb->expr()->eq('f.uid', $qb->quoteIdentifier('r.uid_local')))
                    ->where($qb->expr()->eq('r.tablenames', $qb->createNamedParameter($t)), $qb->expr()->in('r.uid_foreign', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeQuery()->fetchAllAssociative();
                $refs = array_merge($refs, $r);
            }
        }
        return $refs;
    }

    private function writeAssetsList(array $data, string $dir): void
    {
        $ids = array_unique(array_filter(array_map(fn($r) => ltrim($r['identifier'] ?? '', '/'), $data['sys_file_reference'] ?? [])));
        sort($ids);
        file_put_contents($dir . '/robbicopy_assets.txt', implode("\n", $ids) . "\n");
    }

    private function writeBrokenLinksReport(array $data, string $dir): void
    {
        $exp = array_column($data['pages'] ?? [], 'uid');
        $broken = [];
        foreach ($data['tt_content'] ?? [] as $c) {
            foreach (['bodytext', 'header_link', 'pi_flexform'] as $f) {
                if (empty($c[$f]) || !is_string($c[$f])) continue;
                preg_match_all('/t3:\/\/page\?uid=(\d+)/', $c[$f], $m);
                foreach ($m[1] ?? [] as $uid) {
                    if (!in_array((int)$uid, $exp, true)) $broken[] = "tt_content uid={$c['uid']}: t3://page?uid=$uid";
                }
            }
        }
        file_put_contents($dir . '/robbicopy_broken_links.txt', !empty($broken) ? implode("\n", array_unique($broken)) . "\n" : "Keine gebrochenen Links.\n");
    }

    private function checkDependencies(array $pageUids): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $c = $qb->count('uid')->from('sys_file_reference')
            ->where($qb->expr()->in('pid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()->fetchOne();
        if ($c > 0) $this->logger->warning('FAL-Dependency: ' . $c . ' Referenzen');
    }

    private function getYamlConfig(): array
    {
        if ($this->yamlConfigCache !== null) return $this->yamlConfigCache;
        try {
            $this->yamlConfigCache = $this->yamlFileLoader->load('EXT:robbi_copy/robbi_copy.yaml');
        } catch (\Exception $e) { $this->yamlConfigCache = []; }
        return $this->yamlConfigCache;
    }
}
