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
 * Findet bereits importierte Ziel-Records über das Herkunfts-Mapping
 * (tx_impexpnl_uid_map). Grundlage für Delta-/Konfliktprüfung (Import) und
 * Differenzanalyse (Dry-Run) – von beiden Pfaden genutzt.
 */
class ExistingRecordLocator
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UidMapRepository $uidMapRepository
    ) {}

    /**
     * @param array<int, array<string, mixed>> $records Import-Records (mit 'uid')
     * @return array<int, array<string, mixed>> Quell-UID => Ziel-Record-Zeile
     */
    public function find(string $sourceId, string $table, array $records): array
    {
        $sourceUids = array_column($records, 'uid');
        if (empty($sourceUids)) {
            return [];
        }

        $mapping = $this->uidMapRepository->findTargets($sourceId, $table, array_map('intval', $sourceUids));
        if (empty($mapping)) {
            return [];
        }

        // Ziel-Records laden (nur existierende; gelöschte fallen via Restriction
        // raus und werden dadurch als neu behandelt).
        $rowsByTarget = [];
        foreach (array_chunk(array_values($mapping), 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $rows = $qb->select('*')->from($table)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $r) {
                $rowsByTarget[(int)$r['uid']] = $r;
            }
        }

        $map = [];
        foreach ($mapping as $sourceUid => $targetUid) {
            if (isset($rowsByTarget[$targetUid])) {
                $map[(int)$sourceUid] = $rowsByTarget[$targetUid];
            }
        }
        return $map;
    }
}
