<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

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
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Macht einen Import vollständig rückgängig.
     * Entfernt FAL-Referenzen, Registry-Daten, Inhalte und Seiten in sicherer Reihenfolge.
     */
    public function runRollback(?string $importId = null): void
    {
        $this->bootstrapService->initializeBackendContext();

        $connection = $this->connectionPool->getConnectionForTable('tx_robbicopy_import_log');

        // 1. Import-Protokoll finden
        if ($importId) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
            $record = $qb->select('*')
                ->from('tx_robbicopy_import_log')
                ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId)))
                ->executeQuery()
                ->fetchAssociative();

            if (!$record) {
                throw new \RuntimeException("Import-Protokoll für ID '$importId' nicht gefunden.");
            }
        } else {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
            $record = $qb->select('*')
                ->from('tx_robbicopy_import_log')
                ->orderBy('tstamp', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!$record) {
                throw new \RuntimeException('Keine Import-Protokolle gefunden.');
            }

            $importId = $record['import_id'];
        }

        $this->logger->info('Lade Protokoll: ' . $importId);

        $lastImport = json_decode($record['uid_map'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Fehler beim Parsen der UID-Map: ' . json_last_error_msg());
        }

        if (empty($lastImport['pages']) && empty($lastImport['tt_content'])) {
            $this->logger->warning('Protokolldaten sind leer.');
            $connection->delete('tx_robbicopy_import_log', ['import_id' => $importId]);
            return;
        }

        $stats = ['pages' => 0, 'tt_content' => 0, 'sys_file_reference' => 0, 'registry' => 0];

        // 2. FAL-Referenzen bereinigen (VOR dem Löschen der Records)
        foreach (['tt_content', 'pages'] as $tableName) {
            $uids = array_values($lastImport[$tableName] ?? []);
            if (empty($uids)) continue;

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

        // 3. Table-Registry: Alle registrierten Tabellen bereinigen
        $stats['registry'] = $this->tableRegistry->rollbackRegisteredTables($lastImport);

        // 4. DataHandler: Inhalte + Seiten löschen
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

        // 5. Protokoll entfernen + Log schreiben
        $connection->delete('tx_robbicopy_import_log', ['import_id' => $importId]);
        $this->writeRollbackLog($importId, $stats);

        $this->logger->info(sprintf(
            'Rollback %s: %d Seiten, %d Inhalte, %d FAL, %d Registry-Einträge gelöscht.',
            $importId, $stats['pages'], $stats['tt_content'], $stats['sys_file_reference'], $stats['registry']
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
            $logDir . '/robbicopy_transactions.log',
            implode("\n", $lines),
            FILE_APPEND | LOCK_EX
        );
    }
}
