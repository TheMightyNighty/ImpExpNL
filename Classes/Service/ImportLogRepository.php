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

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persistenz des Import-Protokolls (tx_impexpnl_import_log), das die
 * UID-Zuordnung für den Rollback hält.
 */
class ImportLogRepository
{
    private const TABLE = 'tx_impexpnl_import_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @param array<string, array<int,int>> $uidMap
     */
    public function save(string $importId, int $workspaceId, string $sourceFile, bool $delta, array $uidMap): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'import_id' => $importId,
            'tstamp' => time(),
            'workspace_id' => $workspaceId,
            'uid_map' => (string)json_encode($uidMap),
            'source_file' => $sourceFile,
            'delta_mode' => $delta ? 1 : 0,
        ]);
    }

    public function findById(string $importId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    public function findLatest(): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')->from(self::TABLE)
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    public function delete(string $importId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['import_id' => $importId]);
    }
}
