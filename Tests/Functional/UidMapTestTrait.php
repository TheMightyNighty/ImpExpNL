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

namespace Robbi\ImpExpNL\Tests\Functional;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Hilfen für Functional-Tests: Auflösung des Herkunfts-Mappings über die
 * Tabelle tx_impexpnl_uid_map (löst die frühere Spalte tx_impexpnl_remote_uid ab).
 */
trait UidMapTestTrait
{
    /**
     * Ziel-UID eines importierten Records anhand der Quell-UID.
     */
    protected function resolveTargetUid(string $table, int $sourceUid, string $sourceId = ''): ?int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $value = $qb->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where(
                $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId)),
                $qb->expr()->eq('table_name', $qb->createNamedParameter($table)),
                $qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();

        return $value === false ? null : (int)$value;
    }

    /**
     * Anzahl der für eine Tabelle erfassten Herkunfts-Zuordnungen.
     */
    protected function countMappedRecords(string $table, string $sourceId = ''): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        return (int)$qb->count('uid')->from('tx_impexpnl_uid_map')
            ->where(
                $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId)),
                $qb->expr()->eq('table_name', $qb->createNamedParameter($table))
            )
            ->executeQuery()->fetchOne();
    }
}
