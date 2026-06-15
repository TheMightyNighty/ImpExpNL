<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Service\ConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'robbicopy:check',
    description: 'Prüft ob alle Voraussetzungen für Robbi Copy erfüllt sind.'
)]
class CheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configurationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Robbi Copy: Systemprüfung');

        $errors = 0;
        $warnings = 0;

        $io->section('Datenbank');
        try {
            $connection = $this->connectionPool->getConnectionForTable('tx_robbicopy_import_log');
            $schemaManager = $connection->createSchemaManager();

            foreach (['tx_robbicopy_import_log', 'tx_robbicopy_lock'] as $requiredTable) {
                if ($schemaManager->tableExists($requiredTable)) {
                    $io->text("[OK] Tabelle $requiredTable vorhanden.");
                } else {
                    $io->error("Tabelle $requiredTable fehlt. vendor/bin/typo3 database:updateschema ausführen.");
                    $errors++;
                }
            }
        } catch (\Exception $e) {
            $io->error('Datenbankprüfung fehlgeschlagen: ' . $e->getMessage());
            $errors++;
        }

        foreach (['pages', 'tt_content'] as $table) {
            try {
                $schemaManager = $this->connectionPool->getConnectionForTable($table)->createSchemaManager();
                // DBAL 4 (TYPO3 v13): listTableColumns() entfernt → introspectTable() verwenden.
                $columns = $schemaManager->introspectTable($table)->getColumns();
                $columnNames = array_map(fn($c) => $c->getName(), $columns);

                if (in_array('tx_robbicopy_remote_uid', $columnNames, true)) {
                    $io->text("[OK] Feld tx_robbicopy_remote_uid in $table vorhanden.");
                } else {
                    $io->error("Feld tx_robbicopy_remote_uid fehlt in $table. vendor/bin/typo3 database:updateschema ausführen.");
                    $errors++;
                }
            } catch (\Exception $e) {
                $io->error("Prüfung von $table fehlgeschlagen: " . $e->getMessage());
                $errors++;
            }
        }

        $io->section('Dateisystem');
        $varPath = Environment::getVarPath();

        if (is_writable($varPath)) {
            $io->text('[OK] var/-Verzeichnis beschreibbar: ' . $varPath);
        } else {
            $io->error('var/-Verzeichnis nicht beschreibbar: ' . $varPath);
            $errors++;
        }

        $logDir = $varPath . '/log';
        if (is_dir($logDir) && is_writable($logDir)) {
            $io->text('[OK] Log-Verzeichnis beschreibbar: ' . $logDir);
        } elseif (!is_dir($logDir)) {
            $io->warning('Log-Verzeichnis existiert noch nicht (wird beim ersten Import erstellt): ' . $logDir);
            $warnings++;
        } else {
            $io->error('Log-Verzeichnis nicht beschreibbar: ' . $logDir);
            $errors++;
        }

        $profileDir = $varPath . '/robbicopy_profiles';
        if (is_dir($profileDir)) {
            $profiles = glob($profileDir . '/*.yaml');
            $io->text('[OK] Profil-Verzeichnis vorhanden: ' . count($profiles ?: []) . ' Profile gefunden.');
        } else {
            $io->text('[--] Profil-Verzeichnis nicht vorhanden (optional): ' . $profileDir);
        }

        $io->section('Konfiguration');
        try {
            $config = $this->configurationService->getConfig();

            if (!empty($config)) {
                $io->text('[OK] robbi_copy.yaml geladen.');
            } else {
                $io->warning('robbi_copy.yaml ist leer.');
                $warnings++;
            }

            // Table-Registry prüfen (inkl. Extension-Beiträge)
            $tables = $this->configurationService->getRegisteredTables();
            if (!empty($tables)) {
                $io->text('[OK] Table-Registry: ' . count($tables) . ' Tabellen registriert.');
                foreach ($tables as $tableName => $tableConfig) {
                    $type = $tableConfig['type'] ?? 'record';
                    $io->text("     $tableName (type: $type)");
                    foreach ($this->validateTableConfig((string)$tableName, $tableConfig) as $problem) {
                        $io->error($problem);
                        $errors++;
                    }
                }
            } else {
                $io->text('[--] Keine Tabellen in der Registry (optional).');
            }

            // Link-Rewrite-Felder prüfen
            $linkFields = $config['import']['link_rewrite']['fields'] ?? [];
            if (!empty($linkFields)) {
                $io->text('[OK] Link-Rewriting aktiv für: ' . implode(', ', $linkFields));
            }
        } catch (\Exception $e) {
            $io->error('YAML-Konfiguration fehlerhaft: ' . $e->getMessage());
            $errors++;
        }

        $io->section('Extension-Scan');
        $extConfigs = $this->configurationService->getExtensionConfigFiles();
        if (!empty($extConfigs)) {
            foreach ($extConfigs as $packageKey => $file) {
                $io->text('[OK] ' . $packageKey . ' liefert Configuration/RobbiCopy.yaml');
            }
        } else {
            $io->text('[--] Keine Extensions mit eigener RobbiCopy.yaml gefunden (optional).');
        }

        // Zusammenfassung
        $io->section('Ergebnis');
        if ($errors === 0 && $warnings === 0) {
            $io->success('Alle Prüfungen bestanden. Robbi Copy ist einsatzbereit.');
            return Command::SUCCESS;
        }

        if ($errors === 0) {
            $io->warning("$warnings Hinweise, keine Fehler. Robbi Copy ist funktionsfähig.");
            return Command::SUCCESS;
        }

        $io->error("$errors Fehler, $warnings Hinweise. Die Fehler müssen vor dem Einsatz behoben werden.");
        return Command::FAILURE;
    }

    /**
     * Prüft eine einzelne Registry-Definition auf grobe Fehlkonfiguration.
     *
     * @param array<string, mixed> $config
     * @return string[] Liste gefundener Probleme
     */
    private function validateTableConfig(string $tableName, array $config): array
    {
        $problems = [];
        $type = $config['type'] ?? 'record';

        if (!in_array($type, ['mm', 'record'], true)) {
            $problems[] = "Registry '$tableName': ungültiger type '$type' (erlaubt: mm, record).";
            return $problems;
        }

        if ($type === 'mm') {
            if (isset($config['match_tables']) && !is_array($config['match_tables'])) {
                $problems[] = "Registry '$tableName': match_tables muss eine Liste sein.";
            }
            if (isset($config['category_match']) && $config['category_match'] !== 'path') {
                $problems[] = "Registry '$tableName': category_match unterstützt nur 'path'.";
            }
        } else {
            if (isset($config['rewrite_links']) && !is_array($config['rewrite_links'])) {
                $problems[] = "Registry '$tableName': rewrite_links muss eine Liste sein.";
            }
            if (isset($config['uid_remap']) && !is_bool($config['uid_remap'])) {
                $problems[] = "Registry '$tableName': uid_remap muss true/false sein.";
            }
        }

        return $problems;
    }
}
