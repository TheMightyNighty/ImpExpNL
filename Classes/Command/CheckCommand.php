<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;

#[AsCommand(
    name: 'robbicopy:check',
    description: 'Prüft ob alle Voraussetzungen für Robbi Copy erfüllt sind.'
)]
class CheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly YamlFileLoader $yamlFileLoader,
        private readonly PackageManager $packageManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Robbi Copy: Systemprüfung');

        $errors = 0;
        $warnings = 0;

        // 1. Datenbankschema: tx_robbicopy_import_log
        $io->section('Datenbank');
        try {
            $connection = $this->connectionPool->getConnectionForTable('tx_robbicopy_import_log');
            $schemaManager = $connection->createSchemaManager();

            if ($schemaManager->tableExists('tx_robbicopy_import_log')) {
                $io->text('[OK] Tabelle tx_robbicopy_import_log vorhanden.');
            } else {
                $io->error('Tabelle tx_robbicopy_import_log fehlt. vendor/bin/typo3 database:updateschema ausführen.');
                $errors++;
            }
        } catch (\Exception $e) {
            $io->error('Datenbankprüfung fehlgeschlagen: ' . $e->getMessage());
            $errors++;
        }

        // 2. TCA-Felder: tx_robbicopy_remote_uid in pages und tt_content
        foreach (['pages', 'tt_content'] as $table) {
            try {
                $schemaManager = $this->connectionPool->getConnectionForTable($table)->createSchemaManager();
                $columns = $schemaManager->listTableColumns($table);
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

        // 3. Verzeichnisrechte
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

        // 4. YAML-Konfiguration
        $io->section('Konfiguration');
        try {
            $config = $this->yamlFileLoader->load('EXT:robbi_copy/robbi_copy.yaml');

            if (!empty($config)) {
                $io->text('[OK] robbi_copy.yaml geladen.');
            } else {
                $io->warning('robbi_copy.yaml ist leer.');
                $warnings++;
            }

            // Table-Registry prüfen
            $tables = $config['robbicopy']['tables'] ?? [];
            if (!empty($tables)) {
                $io->text('[OK] Table-Registry: ' . count($tables) . ' Tabellen registriert.');
                foreach ($tables as $tableName => $tableConfig) {
                    $type = $tableConfig['type'] ?? 'record';
                    $io->text("     $tableName (type: $type)");
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

        // 5. Extensions mit eigener RobbiCopy.yaml
        $io->section('Extension-Scan');
        try {
            $extConfigs = 0;
            foreach ($this->packageManager->getActivePackages() as $package) {
                $configFile = $package->getPackagePath() . 'Configuration/RobbiCopy.yaml';
                if (file_exists($configFile)) {
                    $io->text('[OK] ' . $package->getPackageKey() . ' liefert Configuration/RobbiCopy.yaml');
                    $extConfigs++;
                }
            }
            if ($extConfigs === 0) {
                $io->text('[--] Keine Extensions mit eigener RobbiCopy.yaml gefunden (optional).');
            }
        } catch (\Exception $e) {
            $io->text('[--] Extension-Scan nicht möglich: ' . $e->getMessage());
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
}
