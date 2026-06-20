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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RollbackService
{
    public function __construct(
        private readonly BootstrapService $bootstrapService,
        private readonly ConnectionPool $connectionPool,
        private readonly TableRegistryService $tableRegistry,
        private readonly ImportLogRepository $importLogRepository,
        private readonly UidMapRepository $uidMapRepository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Liefert eine Vorschau, was ein Rollback entfernen würde, ohne etwas zu ändern.
     *
     * @return array{importId:string, date:string, sourceFile:string, counts:array{pages:int, tt_content:int}, modified:string[]}
     */
    public function preview(?string $importId = null): array
    {
        $record = $importId
            ? $this->importLogRepository->findById($importId)
            : $this->importLogRepository->findLatest();

        if (!$record) {
            throw new \RuntimeException($importId
                ? "Import-Protokoll für ID '$importId' nicht gefunden."
                : 'Keine Import-Protokolle gefunden.');
        }

        $uidMap = json_decode($record['uid_map'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Fehler beim Parsen der UID-Map: ' . json_last_error_msg());
        }

        $importTstamp = (int)($record['tstamp'] ?? 0);

        return [
            'importId' => (string)$record['import_id'],
            'date' => date('Y-m-d H:i:s', $importTstamp),
            'sourceFile' => (string)($record['source_file'] ?? ''),
            'counts' => [
                'pages' => count($uidMap['pages'] ?? []),
                'tt_content' => count($uidMap['tt_content'] ?? []),
            ],
            'modified' => $this->findLocallyModifiedRecords($uidMap, $importTstamp),
        ];
    }

    /**
     * Records, die nach dem Import lokal bearbeitet wurden (tstamp neuer als Import).
     * Ihre Änderungen gingen beim Rollback verloren.
     *
     * @return string[]
     */
    private function findLocallyModifiedRecords(array $uidMap, int $importTstamp): array
    {
        if ($importTstamp <= 0) {
            return [];
        }
        $modified = [];
        foreach (['pages', 'tt_content'] as $table) {
            $uids = array_map('intval', array_values($uidMap[$table] ?? []));
            if (empty($uids)) {
                continue;
            }
            $labelField = $table === 'pages' ? 'title' : 'header';
            foreach (array_chunk($uids, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable($table);
                $qb->getRestrictions()->removeAll();
                $rows = $qb->select('uid', 'tstamp', $labelField)->from($table)
                    ->where(
                        $qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                        $qb->expr()->gt('tstamp', $qb->createNamedParameter($importTstamp, Connection::PARAM_INT))
                    )
                    ->executeQuery()->fetchAllAssociative();
                foreach ($rows as $row) {
                    $modified[] = sprintf('%s uid=%d ("%s")', $table, (int)$row['uid'], (string)($row[$labelField] ?? ''));
                }
            }
        }
        return $modified;
    }

    /**
     * Macht einen Import vollständig rückgängig.
     * Entfernt FAL-Referenzen, Registry-Daten, Inhalte und Seiten in sicherer Reihenfolge.
     */
    public function runRollback(?string $importId = null): void
    {
        $this->bootstrapService->initializeBackendContext();

        $record = $importId
            ? $this->importLogRepository->findById($importId)
            : $this->importLogRepository->findLatest();

        if (!$record) {
            throw new \RuntimeException($importId
                ? "Import-Protokoll für ID '$importId' nicht gefunden."
                : 'Keine Import-Protokolle gefunden.');
        }
        $importId = $record['import_id'];

        $this->logger->info('Lade Protokoll: ' . $importId);

        $lastImport = json_decode($record['uid_map'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Fehler beim Parsen der UID-Map: ' . json_last_error_msg());
        }

        if (empty($lastImport['pages']) && empty($lastImport['tt_content'])) {
            $this->logger->warning('Protokolldaten sind leer.');
            $this->importLogRepository->delete($importId);
            return;
        }

        $stats = ['pages' => 0, 'tt_content' => 0, 'sys_file_reference' => 0, 'registry' => 0];

        // FAL-Referenzen vor den Records selbst entfernen.
        foreach (['tt_content', 'pages'] as $tableName) {
            $uids = array_values($lastImport[$tableName] ?? []);
            if (empty($uids)) {
                continue;
            }

            foreach (array_chunk($uids, 1000) as $chunk) {
                $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
                $stats['sys_file_reference'] += $qb->delete('sys_file_reference')
                    ->where(
                        $qb->expr()->eq('tablenames', $qb->createNamedParameter($tableName)),
                        $qb->expr()->in('uid_foreign', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))
                    )
                    ->executeStatement();
            }
        }

        $stats['registry'] = $this->tableRegistry->rollbackRegisteredTables($lastImport);

        $cmd = [];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        if (!empty($lastImport['tt_content'])) {
            foreach ($lastImport['tt_content'] as $oldUid => $newUid) {
                $cmd['tt_content'][(int)$newUid]['delete'] = 1;
                $stats['tt_content']++;
            }
        }

        if (!empty($lastImport['pages'])) {
            $reversedPages = array_reverse($lastImport['pages'], true);
            foreach ($reversedPages as $oldUid => $newUid) {
                $cmd['pages'][(int)$newUid]['delete'] = 1;
                $stats['pages']++;
            }
        }

        if (!empty($cmd)) {
            $dataHandler->start([], $cmd);
            $dataHandler->process_cmdmap();
        }

        $this->uidMapRepository->deleteByImportId($importId);
        $this->importLogRepository->delete($importId);
        $this->writeRollbackLog($importId, $stats);

        $this->logger->info(sprintf(
            'Rollback %s: %d Seiten, %d Inhalte, %d FAL, %d Registry-Einträge gelöscht.',
            $importId,
            $stats['pages'],
            $stats['tt_content'],
            $stats['sys_file_reference'],
            $stats['registry']
        ));
    }

    private function writeRollbackLog(string $importId, array $stats): void
    {
        $logDir = Environment::getVarPath() . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $lines = [
            str_repeat('=', 72),
            sprintf('[%s] ROLLBACK %s', date('Y-m-d H:i:s'), $importId),
            str_repeat('-', 72),
            sprintf('Seiten gelöscht:         %d', $stats['pages']),
            sprintf('Inhalte gelöscht:        %d', $stats['tt_content']),
            sprintf('FAL-Referenzen:          %d', $stats['sys_file_reference']),
            sprintf('Registry-Einträge:       %d', $stats['registry']),
            str_repeat('=', 72),
            '',
        ];

        file_put_contents(
            $logDir . '/impexpnl_transactions.log',
            implode("\n", $lines),
            FILE_APPEND | LOCK_EX
        );
    }
}
