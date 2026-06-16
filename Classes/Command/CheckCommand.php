<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Command;

use Robbi\ImpExpNL\Service\ConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'impexpnl:check',
    description: 'Prüft ob alle Voraussetzungen für ImpExpNL erfüllt sind.'
)]
class CheckCommand extends Command
{
    /** @var array<int, array{section:string, level:string, message:string}> */
    private array $checks = [];
    private int $errors = 0;
    private int $warnings = 0;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configurationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checks = [];
        $this->errors = 0;
        $this->warnings = 0;

        $this->checkDatabase();
        $this->checkFilesystem();
        $this->checkConfiguration();
        $this->checkExtensionScan();

        $success = $this->errors === 0;

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode([
                'success' => $success,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $success ? Command::SUCCESS : Command::FAILURE;
        }

        $this->render(new SymfonyStyle($input, $output));
        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    private function record(string $section, string $level, string $message): void
    {
        $this->checks[] = ['section' => $section, 'level' => $level, 'message' => $message];
        if ($level === 'error') {
            $this->errors++;
        } elseif ($level === 'warning') {
            $this->warnings++;
        }
    }

    private function checkDatabase(): void
    {
        $section = 'Datenbank';
        try {
            $schemaManager = $this->connectionPool->getConnectionForTable('tx_impexpnl_import_log')->createSchemaManager();
            foreach (['tx_impexpnl_import_log', 'tx_impexpnl_lock'] as $requiredTable) {
                if ($schemaManager->tableExists($requiredTable)) {
                    $this->record($section, 'ok', "Tabelle $requiredTable vorhanden.");
                } else {
                    $this->record($section, 'error', "Tabelle $requiredTable fehlt. vendor/bin/typo3 database:updateschema ausführen.");
                }
            }
        } catch (\Exception $e) {
            $this->record($section, 'error', 'Datenbankprüfung fehlgeschlagen: ' . $e->getMessage());
        }

        foreach (['pages', 'tt_content'] as $table) {
            try {
                $columns = $this->connectionPool->getConnectionForTable($table)->createSchemaManager()->introspectTable($table)->getColumns();
                $columnNames = array_map(static fn($c) => $c->getName(), $columns);
                if (in_array('tx_impexpnl_remote_uid', $columnNames, true)) {
                    $this->record($section, 'ok', "Feld tx_impexpnl_remote_uid in $table vorhanden.");
                } else {
                    $this->record($section, 'error', "Feld tx_impexpnl_remote_uid fehlt in $table. vendor/bin/typo3 database:updateschema ausführen.");
                }
            } catch (\Exception $e) {
                $this->record($section, 'error', "Prüfung von $table fehlgeschlagen: " . $e->getMessage());
            }
        }
    }

    private function checkFilesystem(): void
    {
        $section = 'Dateisystem';
        $varPath = Environment::getVarPath();

        if (is_writable($varPath)) {
            $this->record($section, 'ok', 'var/-Verzeichnis beschreibbar: ' . $varPath);
        } else {
            $this->record($section, 'error', 'var/-Verzeichnis nicht beschreibbar: ' . $varPath);
        }

        $logDir = $varPath . '/log';
        if (is_dir($logDir) && is_writable($logDir)) {
            $this->record($section, 'ok', 'Log-Verzeichnis beschreibbar: ' . $logDir);
        } elseif (!is_dir($logDir)) {
            $this->record($section, 'warning', 'Log-Verzeichnis existiert noch nicht (wird beim ersten Import erstellt): ' . $logDir);
        } else {
            $this->record($section, 'error', 'Log-Verzeichnis nicht beschreibbar: ' . $logDir);
        }

        $profileDir = $varPath . '/impexpnl_profiles';
        if (is_dir($profileDir)) {
            $profiles = glob($profileDir . '/*.yaml');
            $this->record($section, 'ok', 'Profil-Verzeichnis vorhanden: ' . count($profiles ?: []) . ' Profile gefunden.');
        } else {
            $this->record($section, 'info', 'Profil-Verzeichnis nicht vorhanden (optional): ' . $profileDir);
        }
    }

    private function checkConfiguration(): void
    {
        $section = 'Konfiguration';
        try {
            $config = $this->configurationService->getConfig();
            if (!empty($config)) {
                $this->record($section, 'ok', 'imp_exp_nl.yaml geladen.');
            } else {
                $this->record($section, 'warning', 'imp_exp_nl.yaml ist leer.');
            }

            $tables = $this->configurationService->getRegisteredTables();
            if (!empty($tables)) {
                $this->record($section, 'ok', 'Table-Registry: ' . count($tables) . ' Tabellen registriert.');
                foreach ($tables as $tableName => $tableConfig) {
                    foreach ($this->validateTableConfig((string)$tableName, $tableConfig) as $problem) {
                        $this->record($section, 'error', $problem);
                    }
                }
            } else {
                $this->record($section, 'info', 'Keine Tabellen in der Registry (optional).');
            }

            $linkFields = $config['import']['link_rewrite']['fields'] ?? [];
            if (!empty($linkFields)) {
                $this->record($section, 'ok', 'Link-Rewriting aktiv für: ' . implode(', ', $linkFields));
            }
        } catch (\Exception $e) {
            $this->record($section, 'error', 'YAML-Konfiguration fehlerhaft: ' . $e->getMessage());
        }
    }

    private function checkExtensionScan(): void
    {
        $section = 'Extension-Scan';
        $extConfigs = $this->configurationService->getExtensionConfigFiles();
        if (!empty($extConfigs)) {
            foreach ($extConfigs as $packageKey => $file) {
                $this->record($section, 'ok', $packageKey . ' liefert Configuration/ImpExpNL.yaml');
            }
        } else {
            $this->record($section, 'info', 'Keine Extensions mit eigener ImpExpNL.yaml gefunden (optional).');
        }
    }

    private function render(SymfonyStyle $io): void
    {
        $io->title('ImpExpNL: Systemprüfung');

        $currentSection = null;
        foreach ($this->checks as $check) {
            if ($check['section'] !== $currentSection) {
                $currentSection = $check['section'];
                $io->section($currentSection);
            }
            match ($check['level']) {
                'error' => $io->error($check['message']),
                'warning' => $io->warning($check['message']),
                'info' => $io->text('[--] ' . $check['message']),
                default => $io->text('[OK] ' . $check['message']),
            };
        }

        $io->section('Ergebnis');
        if ($this->errors === 0 && $this->warnings === 0) {
            $io->success('Alle Prüfungen bestanden. ImpExpNL ist einsatzbereit.');
        } elseif ($this->errors === 0) {
            $io->warning("$this->warnings Hinweise, keine Fehler. ImpExpNL ist funktionsfähig.");
        } else {
            $io->error("$this->errors Fehler, $this->warnings Hinweise. Die Fehler müssen vor dem Einsatz behoben werden.");
        }
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
