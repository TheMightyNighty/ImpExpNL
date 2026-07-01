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

use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Domain\PageLinkRewriter;
use Robbi\ImpExpNL\Domain\SystemFields;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Deklarative Table-Registry für Export, Import und Rollback beliebiger Tabellen.
 *
 * MM-Tabellen-Config:
 *   type: mm
 *   match_field: uid_foreign
 *   match_tablenames_field: tablenames
 *   match_tables: [pages, tt_content]
 *   category_match: path    # Kategorien über Pfad statt UID matchen
 *
 * Record-Tabellen-Config:
 *   type: record
 *   pid_field: pid
 *   uid_remap: true
 *   rewrite_links: [target]
 */
class TableRegistryService
{
    /** @var array<string, bool> Existenz-Cache je Tabelle. */
    private array $tableExistsCache = [];

    /** @var array<string, array<string, true>> Relations-Container-Felder je Tabelle (Cache). */
    private array $relationFieldCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configurationService,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Existiert die Tabelle im DB-Schema? Erlaubt das Registrieren optionaler Tabellen
     * (z. B. tx_news_*) in der Default-Config, ohne Installationen ohne die Extension
     * mit Warnungen zu fluten – fehlende Tabellen werden still übersprungen.
     */
    private function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }
        try {
            $exists = $this->connectionPool->getConnectionForTable($table)
                ->createSchemaManager()
                ->tablesExist([$table]);
        } catch (\Throwable) {
            $exists = false;
        }
        return $this->tableExistsCache[$table] = $exists;
    }

    /**
     * Relations-Container-Felder (inline/file/category) einer Tabelle. Diese tragen nur einen
     * denormalisierten Zähler; direkt an den DataHandler gereicht würde z. B. `categories: 1`
     * fälschlich eine Relation zu Kategorie-UID 1 erzeugen. Die echten Relationen laufen über
     * die MM-/FAL-Registry, daher werden diese Felder beim Record-Import ausgeblendet.
     *
     * @return array<string, true>
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
        return $this->relationFieldCache[$table] = $fields;
    }

    /**
     * Gibt alle registrierten Tabellen-Definitionen zurück.
     *
     * @return array<string, array> Tabelle → Konfiguration
     */
    public function getRegisteredTables(): array
    {
        return $this->configurationService->getRegisteredTables();
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    /**
     * Exportiert Daten aller registrierten Tabellen basierend auf den übergebenen UIDs.
     *
     * @return array<string, array> Tabelle → Records
     */
    public function exportRegisteredTables(array $pageUids, array $contentUids): array
    {
        $result = [];
        // UID-Sätze fürs MM-Matching: Seiten + Inhalte, ergänzt um die UIDs ALLER
        // registrierten Record-Tabellen (Phase 1) – so werden MM-Relationen (z. B.
        // sys_category) generisch auch für Extension-Record-Tabellen (news …) erfasst.
        $uidSets = ['pages' => $pageUids, 'tt_content' => $contentUids];

        // Phase 1: Record-Tabellen (ihre UIDs braucht Phase 2).
        foreach ($this->getRegisteredTables() as $table => $config) {
            if (($config['type'] ?? 'record') !== 'record' || !$this->tableExists($table)) {
                continue;
            }
            try {
                $records = $this->exportRecordTable($table, $config, $pageUids);
                $result[$table] = $records;
                $uidSets[$table] = array_column($records, 'uid');
                if (!empty($records)) {
                    $this->logger->info("Registry-Export: $table", ['count' => count($records)]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Registry-Export fehlgeschlagen: $table", ['error' => $e->getMessage()]);
            }
        }

        // Phase 2: MM-Tabellen (matchen gegen alle in Phase 1 gesammelten UIDs).
        foreach ($this->getRegisteredTables() as $table => $config) {
            if (($config['type'] ?? 'record') !== 'mm' || !$this->tableExists($table)) {
                continue;
            }
            try {
                $records = $this->exportMmTable($table, $config, $uidSets);
                $isPath = !empty($config['category_match']) && $config['category_match'] === 'path';
                $key = $isPath ? $table . '_with_paths' : $table;
                $result[$key] = $isPath ? $this->enrichMmWithCategoryPaths($records, $config) : $records;
                if (!empty($result[$key])) {
                    $this->logger->info("Registry-Export: $table", ['count' => count($result[$key])]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Registry-Export fehlgeschlagen: $table", ['error' => $e->getMessage()]);
            }
        }
        return $result;
    }

    // =========================================================================
    // IMPORT
    // =========================================================================

    /**
     * Importiert Daten aller registrierten Tabellen mit UID-Remapping.
     *
     * @return int Anzahl der DataHandler-Fehler
     */
    public function importRegisteredTables(array $exportedData, array &$uidMap): int
    {
        $errors = 0;

        // Phase 1: Record-Tabellen zuerst – ihre neuen UIDs landen in $uidMap und werden
        // von der MM-Phase gebraucht (uid_foreign → neue Record-UID über tablenames).
        foreach ($this->getRegisteredTables() as $table => $config) {
            if (($config['type'] ?? 'record') !== 'record' || !$this->tableExists($table) || empty($exportedData[$table])) {
                continue;
            }
            try {
                $errors += $this->importRecordTable($table, $config, $exportedData[$table], $uidMap);
            } catch (\Exception $e) {
                $this->logger->warning("Registry-Import fehlgeschlagen: $table", ['error' => $e->getMessage()]);
            }
        }

        // Phase 2: MM-Tabellen (uid_foreign gegen die nun bekannten Record-UIDs auflösen).
        foreach ($this->getRegisteredTables() as $table => $config) {
            if (($config['type'] ?? 'record') !== 'mm' || !$this->tableExists($table)) {
                continue;
            }
            $isPath = !empty($config['category_match']) && $config['category_match'] === 'path';
            $dataKey = $isPath ? $table . '_with_paths' : $table;
            if (empty($exportedData[$dataKey])) {
                continue;
            }
            try {
                if ($isPath) {
                    $this->importMmTableWithPathMatching($table, $config, $exportedData[$dataKey], $uidMap);
                } else {
                    $this->importMmTable($table, $config, $exportedData[$dataKey], $uidMap);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Registry-Import fehlgeschlagen: $table", ['error' => $e->getMessage()]);
            }
        }
        return $errors;
    }

    // =========================================================================
    // ROLLBACK
    // =========================================================================

    /**
     * Entfernt alle über die Registry importierten Daten (MM-Relationen und Records).
     *
     * @return int Anzahl gelöschter Einträge
     */
    public function rollbackRegisteredTables(array $uidMap): int
    {
        $total = 0;
        foreach ($this->getRegisteredTables() as $table => $config) {
            if (!$this->tableExists($table)) {
                continue;
            }
            try {
                $type = $config['type'] ?? 'record';
                if ($type === 'mm') {
                    $total += $this->rollbackMmTable($table, $config, $uidMap);
                } elseif ($type === 'record') {
                    $total += $this->rollbackRecordTable($table, $config, $uidMap);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Registry-Rollback: $table", ['error' => $e->getMessage()]);
            }
        }
        return $total;
    }

    // =========================================================================
    // MM-TABELLEN
    // =========================================================================

    /**
     * @param array<string, int[]> $uidSets tablenames => UIDs (pages/tt_content + registrierte Record-Tabellen)
     */
    private function exportMmTable(string $table, array $config, array $uidSets): array
    {
        $matchField = $config['match_field'] ?? 'uid_foreign';
        $tnField = $config['match_tablenames_field'] ?? 'tablenames';
        $matchTables = $config['match_tables'] ?? ['pages', 'tt_content'];
        $records = [];

        foreach ($matchTables as $mt) {
            $uids = $uidSets[$mt] ?? [];
            if (empty($uids)) {
                continue;
            }
            foreach (array_chunk($uids, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable($table);
                $qb->getRestrictions()->removeAll();
                $rows = $qb->select('*')->from($table)
                    ->where($qb->expr()->eq($tnField, $qb->createNamedParameter($mt)), $qb->expr()->in($matchField, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeQuery()->fetchAllAssociative();
                $records = array_merge($records, $rows);
            }
        }
        return $records;
    }

    /**
     * Kategorie-Pfad-Mapping: Ergänzt MM-Records um den vollständigen Kategorie-Pfad.
     */
    private function enrichMmWithCategoryPaths(array $records, array $config): array
    {
        $enriched = [];
        foreach ($records as $rec) {
            $categoryUid = (int)($rec['uid_local'] ?? 0);
            $rec['_category_path'] = $this->buildCategoryPath($categoryUid);
            $enriched[] = $rec;
        }
        return $enriched;
    }

    /**
     * Baut den vollständigen Kategorie-Pfad rekursiv auf: "Themen > Digitalisierung > E-Government"
     */
    protected function buildCategoryPath(int $uid): string
    {
        $parts = [];
        $current = $uid;
        $maxDepth = 20; // Schutz vor Endlosschleifen

        while ($current > 0 && $maxDepth-- > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $cat = $qb->select('uid', 'title', 'parent')->from('sys_category')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($current, Connection::PARAM_INT)))
                ->executeQuery()->fetchAssociative();

            if (!$cat) {
                break;
            }
            array_unshift($parts, $cat['title']);
            $current = (int)$cat['parent'];
        }

        return implode(' > ', $parts);
    }

    /**
     * Löst einen Kategorie-Pfad auf dem Zielsystem auf → uid_local.
     * Erstellt fehlende Kategorien automatisch.
     */
    protected function resolveCategoryByPath(string $path): ?int
    {
        $segments = array_filter(array_map('trim', explode('>', $path)), static fn(string $s): bool => $s !== '');
        if ($segments === []) {
            return null;
        }

        $parentUid = 0;
        foreach ($segments as $segment) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $existing = $qb->select('uid')->from('sys_category')
                ->where(
                    $qb->expr()->eq('title', $qb->createNamedParameter($segment)),
                    $qb->expr()->eq('parent', $qb->createNamedParameter($parentUid, Connection::PARAM_INT))
                )
                ->executeQuery()->fetchAssociative();

            if ($existing) {
                $parentUid = (int)$existing['uid'];
            } else {
                // Kategorie automatisch anlegen
                $dh = GeneralUtility::makeInstance(DataHandler::class);
                $tempId = 'NEW_CAT_' . md5($path . $segment . $parentUid);
                $dh->start(['sys_category' => [$tempId => [
                    'pid' => 0,
                    'title' => $segment,
                    'parent' => $parentUid,
                ]]], []);
                $dh->process_datamap();
                $parentUid = (int)($dh->substNEWwithIDs[$tempId] ?? 0);
                if ($parentUid === 0) {
                    return null;
                }
                $this->logger->info("Kategorie angelegt: $segment (uid=$parentUid, parent=$parentUid)");
            }
        }

        return $parentUid > 0 ? $parentUid : null;
    }

    private function importMmTable(string $table, array $config, array $records, array $uidMap): void
    {
        $matchField = $config['match_field'] ?? 'uid_foreign';
        $tnField = $config['match_tablenames_field'] ?? 'tablenames';
        $conn = $this->connectionPool->getConnectionForTable($table);
        $rows = [];

        foreach ($records as $rec) {
            $relTable = $rec[$tnField] ?? '';
            $oldForeign = (int)($rec[$matchField] ?? 0);
            if (!isset($uidMap[$relTable][$oldForeign])) {
                continue;
            }
            $newForeign = (int)$uidMap[$relTable][$oldForeign];

            $del = [$matchField => $newForeign, $tnField => $relTable];
            if (!empty($rec['uid_local'])) {
                $del['uid_local'] = (int)$rec['uid_local'];
            }
            if (!empty($rec['fieldname'])) {
                $del['fieldname'] = $rec['fieldname'];
            }
            $conn->delete($table, $del);

            $ins = $rec;
            $ins[$matchField] = $newForeign;
            unset($ins['_category_path']);
            $rows[] = $ins;
        }

        $imported = $this->bulkInsertRows($table, $rows);
        if ($imported > 0) {
            $this->logger->info("MM-Import: $table", ['imported' => $imported]);
        }
    }

    /**
     * Fügt gleichförmige Zeilen gebündelt ein (chunked), statt einzeln.
     */
    private function bulkInsertRows(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }
        $conn = $this->connectionPool->getConnectionForTable($table);
        $imported = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $columns = array_keys($chunk[0]);
            $values = array_map(
                static fn(array $row): array => array_map(static fn(string $col) => $row[$col] ?? null, $columns),
                $chunk
            );
            $imported += $conn->bulkInsert($table, $values, $columns);
        }
        return $imported;
    }

    /**
     * Pfad-basiertes Kategorie-Mapping: uid_local wird über den Pfad aufgelöst.
     */
    private function importMmTableWithPathMatching(string $table, array $config, array $records, array $uidMap): void
    {
        $matchField = $config['match_field'] ?? 'uid_foreign';
        $tnField = $config['match_tablenames_field'] ?? 'tablenames';
        $conn = $this->connectionPool->getConnectionForTable($table);
        $pathCache = [];
        $rows = [];

        foreach ($records as $rec) {
            $relTable = $rec[$tnField] ?? '';
            $oldForeign = (int)($rec[$matchField] ?? 0);
            if (!isset($uidMap[$relTable][$oldForeign])) {
                continue;
            }
            $newForeign = (int)$uidMap[$relTable][$oldForeign];

            $path = $rec['_category_path'] ?? '';
            if (empty($path)) {
                continue;
            }

            if (!isset($pathCache[$path])) {
                $pathCache[$path] = $this->resolveCategoryByPath($path);
            }
            $newCategoryUid = $pathCache[$path];
            if ($newCategoryUid === null) {
                $this->logger->warning("Kategorie-Pfad nicht auflösbar: $path");
                continue;
            }

            $del = [$matchField => $newForeign, $tnField => $relTable, 'uid_local' => $newCategoryUid];
            if (!empty($rec['fieldname'])) {
                $del['fieldname'] = $rec['fieldname'];
            }
            $conn->delete($table, $del);

            $ins = $rec;
            $ins[$matchField] = $newForeign;
            $ins['uid_local'] = $newCategoryUid;
            unset($ins['_category_path']);
            $rows[] = $ins;
        }

        $imported = $this->bulkInsertRows($table, $rows);
        if ($imported > 0) {
            $this->logger->info("MM-Import (Pfad): $table", ['imported' => $imported]);
        }
    }

    private function rollbackMmTable(string $table, array $config, array $uidMap): int
    {
        $matchField = $config['match_field'] ?? 'uid_foreign';
        $tnField = $config['match_tablenames_field'] ?? 'tablenames';
        $matchTables = $config['match_tables'] ?? ['pages', 'tt_content'];
        $deleted = 0;

        foreach ($matchTables as $mt) {
            $uids = array_values($uidMap[$mt] ?? []);
            if (empty($uids)) {
                continue;
            }
            foreach (array_chunk($uids, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable($table);
                $qb->getRestrictions()->removeAll();
                $deleted += $qb->delete($table)
                    ->where($qb->expr()->eq($tnField, $qb->createNamedParameter($mt)), $qb->expr()->in($matchField, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }
        }
        return $deleted;
    }

    // =========================================================================
    // RECORD-TABELLEN
    // =========================================================================

    private function exportRecordTable(string $table, array $config, array $pageUids): array
    {
        $pidField = $config['pid_field'] ?? 'pid';
        $records = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $rows = $qb->select('*')->from($table)
                ->where($qb->expr()->in($pidField, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->orderBy('uid', 'ASC')
                ->executeQuery()->fetchAllAssociative();
            $records = array_merge($records, $rows);
        }
        return $records;
    }

    private function importRecordTable(string $table, array $config, array $records, array &$uidMap): int
    {
        if (empty($records)) {
            return 0;
        }
        $pidField = $config['pid_field'] ?? 'pid';
        $uidRemap = $config['uid_remap'] ?? false;
        $rewriteLinks = $config['rewrite_links'] ?? [];

        if (!isset($uidMap[$table])) {
            $uidMap[$table] = [];
        }

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $datamap = [];
        $relationFields = $this->getRelationContainerFields($table);

        foreach ($records as $rec) {
            $oldUid = (int)($rec['uid'] ?? 0);
            $oldPid = (int)($rec[$pidField] ?? 0);
            $newPid = $uidMap['pages'][$oldPid] ?? $oldPid;

            $data = [];
            foreach ($rec as $f => $v) {
                if (in_array($f, SystemFields::EXCLUDED, true) || isset($relationFields[$f])) {
                    continue;
                }
                $data[$f] = $v;
            }
            $data[$pidField] = $newPid;
            $data['pid'] = $newPid;

            foreach ($rewriteLinks as $lf) {
                if (!empty($data[$lf]) && is_string($data[$lf])) {
                    $data[$lf] = $this->rewritePageLinks($data[$lf], $uidMap);
                }
            }

            $datamap[$table]['NEW_REG_' . $table . '_' . $oldUid] = $data;
        }

        $dh->start($datamap, []);
        $dh->process_datamap();

        $errors = 0;
        if (!empty($dh->errorLog)) {
            $errors = count($dh->errorLog);
            foreach ($dh->errorLog as $error) {
                $this->logger->error("DataHandler ($table): $error");
            }
        }

        if ($uidRemap) {
            $prefix = 'NEW_REG_' . $table . '_';
            foreach ($dh->substNEWwithIDs as $ph => $newUid) {
                if (str_starts_with($ph, $prefix)) {
                    $uidMap[$table][(int)str_replace($prefix, '', $ph)] = (int)$newUid;
                }
            }
        }
        $this->logger->info("Record-Import: $table", ['count' => count($records)]);
        return $errors;
    }

    private function rollbackRecordTable(string $table, array $config, array $uidMap): int
    {
        $uids = array_values($uidMap[$table] ?? []);
        if (empty($uids)) {
            return 0;
        }
        $cmd = [];
        foreach ($uids as $uid) {
            $cmd[$table][(int)$uid]['delete'] = 1;
        }
        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], $cmd);
        $dh->process_cmdmap();
        return count($uids);
    }

    protected function rewritePageLinks(string $text, array $uidMap): string
    {
        return PageLinkRewriter::rewrite($text, $uidMap['pages'] ?? []);
    }
}
