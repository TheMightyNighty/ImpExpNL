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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persistentes Herkunfts-Mapping (Quell-Record -> Ziel-Record) für idempotente
 * Importe. Löst die früheren tx_impexpnl_remote_uid-Spalten auf pages/tt_content
 * ab: Core-Tabellen bleiben unangetastet, das Mapping gilt einheitlich für alle
 * Tabellen und unterscheidet Quellsysteme über source_id.
 */
class UidMapRepository
{
    private const TABLE = 'tx_impexpnl_uid_map';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Liefert für eine Quelle und Tabelle die Zuordnung source_uid => target_uid
     * für die angefragten Quell-UIDs.
     *
     * @param int[] $sourceUids
     * @return array<int,int>
     */
    public function findTargets(string $sourceId, string $table, array $sourceUids): array
    {
        if (empty($sourceUids)) {
            return [];
        }

        $map = [];
        foreach (array_chunk($sourceUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $rows = $qb->select('source_uid', 'target_uid')->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId)),
                    $qb->expr()->eq('table_name', $qb->createNamedParameter($table)),
                    $qb->expr()->in('source_uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))
                )
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $map[(int)$row['source_uid']] = (int)$row['target_uid'];
            }
        }
        return $map;
    }

    /**
     * Schreibt die neu erzeugten Zuordnungen eines Imports. Bestehende Einträge
     * derselben Quelle/Tabelle/Quell-UID werden zuvor entfernt (Re-Import nach
     * Löschung des Ziel-Records), damit der Unique-Index nicht kollidiert.
     *
     * @param array<string, array<int,int>> $rollbackMap table => [sourceUid => targetUid]
     */
    public function persist(string $sourceId, string $importId, array $rollbackMap): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();

        $connection->beginTransaction();
        try {
            $this->writeMappings($connection, $sourceId, $importId, $rollbackMap, $now);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, array<int,int>> $rollbackMap
     */
    private function writeMappings(Connection $connection, string $sourceId, string $importId, array $rollbackMap, int $now): void
    {
        foreach ($rollbackMap as $table => $entries) {
            if (empty($entries)) {
                continue;
            }
            foreach (array_chunk($entries, 1000, true) as $chunk) {
                $sourceUids = array_map('intval', array_keys($chunk));
                $deleteQb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
                $deleteQb->delete(self::TABLE)
                    ->where(
                        $deleteQb->expr()->eq('source_id', $deleteQb->createNamedParameter($sourceId)),
                        $deleteQb->expr()->eq('table_name', $deleteQb->createNamedParameter((string)$table)),
                        $deleteQb->expr()->in('source_uid', $deleteQb->createNamedParameter($sourceUids, Connection::PARAM_INT_ARRAY))
                    )
                    ->executeStatement();

                foreach ($chunk as $sourceUid => $targetUid) {
                    $connection->insert(self::TABLE, [
                        'source_id' => $sourceId,
                        'table_name' => (string)$table,
                        'source_uid' => (int)$sourceUid,
                        'target_uid' => (int)$targetUid,
                        'import_id' => $importId,
                        'crdate' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Entfernt alle Mappings eines Imports (Rollback-Cleanup).
     */
    public function deleteByImportId(string $importId): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->delete(self::TABLE)
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId)))
            ->executeStatement();
    }
}
