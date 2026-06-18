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
use Robbi\ImpExpNL\Event\ModifyExportDataEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ExportService
{
    /**
     * Marketing-/Release-Version der Extension. Bei jedem Release anpassen.
     * (Die maschinell relevante Format-Version steht in IntegrityService::FORMAT_VERSION.)
     */
    public const VERSION = '5.0.0';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly BootstrapService $bootstrapService,
        private readonly TableRegistryService $tableRegistry,
        private readonly ConfigurationService $configurationService,
        private readonly SiteFinder $siteFinder,
        private readonly IntegrityService $integrityService,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ExportWriter $exportWriter,
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

        $this->exportWriter->write(
            $finalData,
            $filePath,
            !empty($options['jsonl']),
            !empty($options['csv'])
        );
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

        if (!empty($explicitPages)) {
            $pageUids = $this->collectExplicitPages($explicitPages, $data['pages'], $includeHidden);
        } else {
            $pageUids = $this->collectPageTree($startPid, $data['pages'], $includeHidden, $depth, 0);
        }

        if (!empty($excludePages)) {
            $pageUids = array_diff($pageUids, $excludePages);
            $data['pages'] = array_filter($data['pages'], fn($p) => !in_array((int)$p['uid'], $excludePages, true));
            $data['pages'] = array_values($data['pages']);
        }

        if (empty($pageUids)) {
            $this->logger->warning('Keine Seiten gefunden');
            return $data;
        }

        if ($onProgress) {
            $onProgress('Seiten gesammelt', count($pageUids), 0);
        }

        // Inhalte laden
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $this->applyRestrictions($qb, $includeHidden);

            $where = [$qb->expr()->in('pid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))];

            if ($sinceTimestamp > 0) {
                $where[] = $qb->expr()->gte('tstamp', $qb->createNamedParameter($sinceTimestamp, Connection::PARAM_INT));
            }

            if (!empty($contentTypes)) {
                $where[] = $qb->expr()->in('CType', $qb->createNamedParameter($contentTypes, Connection::PARAM_STR_ARRAY));
            }

            $contents = $qb->select('*')->from('tt_content')
                ->where(...$where)
                ->orderBy('sorting', 'ASC')
                ->executeQuery()->fetchAllAssociative();

            $data['tt_content'] = array_merge($data['tt_content'], $contents);
        }

        if ($onProgress) {
            $onProgress('Inhalte geladen', count($data['tt_content']), 0);
        }

        $data['_site_config'] = $this->exportSiteConfig($pageUids);
        $data['irre_relations'] = $this->exportInlineRelations($data['tt_content']);

        if ($this->configurationService->isFileReferencesEnabled('export')) {
            $data['sys_file_reference'] = $this->exportFileReferences($pageUids, $data['tt_content']);
        }

        $contentUids = array_column($data['tt_content'], 'uid');
        $registryData = $this->tableRegistry->exportRegisteredTables($pageUids, $contentUids);
        $data = array_merge($data, $registryData);

        if ($onProgress) {
            $onProgress('Registry + FAL exportiert', 0, 0);
        }

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

            $qb2 = $this->connectionPool->getQueryBuilderForTable('pages');
            $this->applyRestrictions($qb2, $includeHidden);
            $translations = $qb2->select('*')->from('pages')
                ->where($qb2->expr()->in('l10n_parent', $qb2->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($translations as $t) {
                $pagesData[] = $t;
            }
        }
        return $collectedUids;
    }

    /**
     * Sammelt den Seitenbaum ebenen-weise ein.
     *
     * Statt einer Query pro Seite (N+1) wird pro Baumebene genau eine Query für
     * die Seiten (+ deren Übersetzungen) und eine für die Kind-UIDs abgesetzt.
     * Eltern erscheinen stets vor ihren Kindern (Breitensuche), was für die
     * pid-/l10n_parent-Auflösung beim Import erforderlich ist.
     */
    protected function collectPageTree(int $pid, array &$pagesData, bool $includeHidden, int $maxDepth, int $currentDepth): array
    {
        $collectedUids = [];
        $currentLevel = [$pid];
        $depth = $currentDepth;

        while (!empty($currentLevel)) {
            $levelDefaultUids = [];
            foreach (array_chunk($currentLevel, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable('pages');
                $this->applyRestrictions($qb, $includeHidden);
                $rows = $qb->select('*')->from('pages')
                    ->where($qb->expr()->or(
                        $qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                        $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))
                    ))
                    ->orderBy('sorting', 'ASC')
                    ->executeQuery()->fetchAllAssociative();

                foreach ($rows as $row) {
                    $pagesData[] = $row;
                    if ((int)$row['sys_language_uid'] === 0 && in_array((int)$row['uid'], $currentLevel, true)) {
                        $collectedUids[] = (int)$row['uid'];
                        $levelDefaultUids[] = (int)$row['uid'];
                    }
                }
            }

            if (($maxDepth > 0 && $depth >= $maxDepth) || empty($levelDefaultUids)) {
                break;
            }

            $nextLevel = [];
            foreach (array_chunk($levelDefaultUids, 1000) as $chunk) {
                $qbC = $this->connectionPool->getQueryBuilderForTable('pages');
                $this->applyRestrictions($qbC, $includeHidden);
                $children = $qbC->select('uid')->from('pages')
                    ->where(
                        $qbC->expr()->in('pid', $qbC->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                        $qbC->expr()->eq('sys_language_uid', $qbC->createNamedParameter(0, Connection::PARAM_INT))
                    )
                    ->orderBy('sorting', 'ASC')
                    ->executeQuery()->fetchAllAssociative();
                foreach ($children as $child) {
                    $nextLevel[] = (int)$child['uid'];
                }
            }

            $currentLevel = $nextLevel;
            $depth++;
        }

        return $collectedUids;
    }

    /**
     * Sammelt Identifier, Basis-URL und Sprachen der beteiligten Sites.
     *
     * Sobald so viele unterschiedliche Sites gefunden wurden, wie überhaupt
     * konfiguriert sind, wird die Schleife abgebrochen – im häufigen Fall einer
     * einzigen Site genügt damit ein getSiteByPageId()-Aufruf statt einem je Seite.
     */
    private function exportSiteConfig(array $pageUids): array
    {
        try {
            $totalSites = count($this->siteFinder->getAllSites());
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
                    // Seite gehört zu keiner Site.
                }
                if ($totalSites > 0 && count($sites) >= $totalSites) {
                    break;
                }
            }
            return array_values($sites);
        } catch (\Exception $e) {
            $this->logger->warning('Site-Konfiguration konnte nicht exportiert werden: ' . $e->getMessage());
            return [];
        }
    }

    protected function applyRestrictions(QueryBuilder $qb, bool $includeHidden): void
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
        if (empty($contentRecords)) {
            return [];
        }
        $relations = [];
        $contentUids = array_column($contentRecords, 'uid');

        if (!$this->tcaSchemaFactory->has('tt_content')) {
            return [];
        }

        foreach ($this->tcaSchemaFactory->get('tt_content')->getFields() as $field) {
            if (!$field->isType(TableColumnType::INLINE)) {
                continue;
            }
            $config = $field->getConfiguration();
            if (empty($config['foreign_table'])) {
                continue;
            }

            $fieldName = $field->getName();
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
                    $this->logger->debug("IRRE-Tabelle '$foreignTable' nicht verfügbar: " . $e->getMessage());
                }
            }
        }
        return $relations;
    }

    protected function buildExportMeta(int $startPid, array $data, array $options): array
    {
        $typo3Version = class_exists(Typo3Version::class) ? GeneralUtility::makeInstance(Typo3Version::class)->getVersion() : 'unknown';

        return [
            'export_version' => self::VERSION,
            'export_format' => IntegrityService::FORMAT_VERSION,
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
            // Prüfsumme/Signatur über den gesamten Datenblock (alle Tabellen, ohne _meta)
            'checksum' => $this->integrityService->sign($data),
        ];
    }

    protected function parseSince(?string $since): int
    {
        if (empty($since)) {
            return 0;
        }
        if (is_numeric($since)) {
            return (int)$since;
        }
        $ts = strtotime($since);
        return $ts !== false ? $ts : 0;
    }

    protected function exportFileReferences(array $pageUids, array $contentRecords): array
    {
        $refs = [];
        $cUids = array_column($contentRecords, 'uid');
        foreach (['pages' => $pageUids, 'tt_content' => $cUids] as $t => $uids) {
            if (empty($uids)) {
                continue;
            }
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

    private function checkDependencies(array $pageUids): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $c = $qb->count('uid')->from('sys_file_reference')
            ->where($qb->expr()->in('pid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()->fetchOne();
        if ($c > 0) {
            $this->logger->warning('FAL-Dependency: ' . $c . ' Referenzen');
        }
    }
}
