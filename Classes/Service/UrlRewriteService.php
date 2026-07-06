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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ersetzt absolute Domain-URLs im Content der importierten Records – feldbezogen,
 * literal oder per Regex. **Bewusst getrennt** vom UID-basierten t3://page-Link-
 * Rewriter ({@see LinkRewriterService}): URL-Ersetzung ist eine eigene, explizit
 * konfigurierte Operation (Footgun-Risiko bei zu breiten Mustern).
 *
 * Konfiguration unter `import.rewrite_urls` (Default aus):
 *   enabled: true
 *   fields: [bodytext, header_link]
 *   rules:
 *     - { search: 'https://www.prod.example.com', replace: 'https://dev.example.com' }
 *     - { search: '#https?://(stage|prod)\.example\.com#', replace: 'https://dev.example.com', regex: true }
 */
class UrlRewriteService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string, array<int,int>> $uidMap
     * @param list<array{search?:string, replace?:string, regex?:bool}> $rules
     * @param list<string> $fields
     * @return int Anzahl der geänderten Felder
     */
    public function rewrite(array $uidMap, array $rules, array $fields, int $workspaceId): int
    {
        if ($rules === [] || $fields === []) {
            return 0;
        }

        $dataMap = [];
        $changed = 0;
        foreach (['pages', 'tt_content'] as $table) {
            foreach ($uidMap[$table] ?? [] as $newUid) {
                $row = $this->fetchRecord($table, (int)$newUid, $workspaceId);
                if (!$row) {
                    continue;
                }
                $update = [];
                foreach ($fields as $field) {
                    if (empty($row[$field]) || !is_string($row[$field])) {
                        continue;
                    }
                    $new = $this->applyRules($row[$field], $rules);
                    if ($new !== $row[$field]) {
                        $update[$field] = $new;
                    }
                }
                if ($update !== []) {
                    $dataMap[$table][(int)$newUid] = $update;
                    $changed += count($update);
                }
            }
        }

        if ($dataMap !== []) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
        }

        return $changed;
    }

    /**
     * Wendet die Ersetzungsregeln auf einen einzelnen Wert an (rein, ohne DB).
     *
     * @param list<array{search?:string, replace?:string, regex?:bool}> $rules
     */
    public function applyRules(string $value, array $rules): string
    {
        foreach ($rules as $rule) {
            $search = (string)($rule['search'] ?? '');
            if ($search === '') {
                continue;
            }
            $replace = (string)($rule['replace'] ?? '');
            if (!empty($rule['regex'])) {
                $result = @preg_replace($search, $replace, $value);
                if ($result === null) {
                    $this->logger->warning('rewrite_urls: invalid regex skipped', ['pattern' => $search]);
                    continue;
                }
                $value = $result;
            } else {
                $value = str_replace($search, $replace, $value);
            }
        }
        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRecord(string $table, int $uid, int $workspaceId): ?array
    {
        if ($workspaceId > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $version = $qb->select('*')->from($table)
                ->where(
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                    $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($workspaceId, Connection::PARAM_INT))
                )
                ->setMaxResults(1)->executeQuery()->fetchAssociative();
            if ($version) {
                return $version;
            }
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();

        return $row ?: null;
    }
}
