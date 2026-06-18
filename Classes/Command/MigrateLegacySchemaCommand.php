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

namespace Robbi\ImpExpNL\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Migriert das Alt-Schema der Vorgänger-Extension "robbi_copy" auf die
 * ImpExpNL-Namen:
 *   - Spalte  tx_robbicopy_remote_uid → tx_impexpnl_remote_uid  (pages, tt_content)
 *   - Tabelle tx_robbicopy_import_log → tx_impexpnl_import_log
 *   - Tabelle tx_robbicopy_lock       (verworfen – Locks sind flüchtig)
 *
 * Voraussetzung: Das neue Schema (tx_impexpnl_*) wurde bereits angelegt
 * (z. B. `typo3 extension:setup` bzw. DB-Compare). Der Befehl ist idempotent
 * und kann in einer Pipeline gefahrlos mehrfach laufen.
 */
#[AsCommand(
    name: 'impexpnl:migrate-legacy-schema',
    description: 'Übernimmt Daten aus dem alten robbi_copy-Schema (tx_robbicopy_*) in das ImpExpNL-Schema.'
)]
class MigrateLegacySchemaCommand extends Command
{
    private const COLUMN_TABLES = ['pages', 'tt_content'];
    private const LEGACY_COLUMN = 'tx_robbicopy_remote_uid';
    private const NEW_COLUMN = 'tx_impexpnl_remote_uid';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('drop-legacy', null, InputOption::VALUE_NONE, 'Alte Spalten/Tabellen nach der Übernahme entfernen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dropLegacy = (bool)$input->getOption('drop-legacy');
        $didSomething = false;

        // 1) Spalten: remote_uid je Tabelle übernehmen.
        foreach (self::COLUMN_TABLES as $table) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $columns = $this->columnNames($table);
            if (!isset($columns[self::LEGACY_COLUMN])) {
                continue;
            }
            if (!isset($columns[self::NEW_COLUMN])) {
                $io->warning(sprintf('%s: neue Spalte %s fehlt – bitte zuerst das Schema aktualisieren (extension:setup).', $table, self::NEW_COLUMN));
                continue;
            }

            $affected = $connection->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = %s WHERE %s = 0 AND %s <> 0',
                    $connection->quoteIdentifier($table),
                    $connection->quoteIdentifier(self::NEW_COLUMN),
                    $connection->quoteIdentifier(self::LEGACY_COLUMN),
                    $connection->quoteIdentifier(self::NEW_COLUMN),
                    $connection->quoteIdentifier(self::LEGACY_COLUMN)
                )
            );
            $io->text(sprintf('%s: %d Zeilen von %s übernommen.', $table, $affected, self::LEGACY_COLUMN));
            $didSomething = true;

            if ($dropLegacy) {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE %s DROP COLUMN %s',
                    $connection->quoteIdentifier($table),
                    $connection->quoteIdentifier(self::LEGACY_COLUMN)
                ));
                $io->text(sprintf('%s: alte Spalte %s entfernt.', $table, self::LEGACY_COLUMN));
            }
        }

        // 2) Import-Log-Tabelle übernehmen.
        if ($this->tableExists('tx_robbicopy_import_log')) {
            $this->copyTable('tx_robbicopy_import_log', 'tx_impexpnl_import_log', $io);
            $didSomething = true;
            if ($dropLegacy) {
                $this->dropTable('tx_robbicopy_import_log', $io);
            }
        }

        // 3) Lock-Tabelle: Inhalte sind flüchtig, nur die Alt-Tabelle entfernen.
        if ($this->tableExists('tx_robbicopy_lock') && $dropLegacy) {
            $this->dropTable('tx_robbicopy_lock', $io);
            $didSomething = true;
        }

        if (!$didSomething) {
            $io->success('Kein Alt-Schema (robbi_copy) gefunden – nichts zu migrieren.');
            return Command::SUCCESS;
        }

        $io->success($dropLegacy
            ? 'Migration abgeschlossen, Alt-Schema entfernt.'
            : 'Migration abgeschlossen. Mit --drop-legacy lassen sich die Alt-Spalten/-Tabellen entfernen.');
        return Command::SUCCESS;
    }

    /**
     * @return array<string, true> Spaltenname → true
     */
    private function columnNames(string $table): array
    {
        $schemaManager = $this->connectionPool->getConnectionForTable($table)->createSchemaManager();
        $names = [];
        foreach ($schemaManager->introspectTable($table)->getColumns() as $column) {
            $names[$column->getName()] = true;
        }
        return $names;
    }

    private function tableExists(string $table): bool
    {
        $schemaManager = $this->connectionPool->getConnectionForTable($table)->createSchemaManager();
        return in_array($table, $schemaManager->listTableNames(), true);
    }

    private function copyTable(string $from, string $to, SymfonyStyle $io): void
    {
        $connection = $this->connectionPool->getConnectionForTable($to);
        // Gemeinsame Spalten verwenden (alt und neu sind strukturgleich, nur umbenannt).
        $columns = array_keys($this->columnNames($to));
        $quoted = implode(', ', array_map($connection->quoteIdentifier(...), $columns));
        $affected = $connection->executeStatement(sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s',
            $connection->quoteIdentifier($to),
            $quoted,
            $quoted,
            $connection->quoteIdentifier($from)
        ));
        $io->text(sprintf('%s → %s: %d Zeilen übernommen.', $from, $to, $affected));
    }

    private function dropTable(string $table, SymfonyStyle $io): void
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->executeStatement('DROP TABLE ' . $connection->quoteIdentifier($table));
        $io->text(sprintf('Alte Tabelle %s entfernt.', $table));
    }
}
