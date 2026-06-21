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

use Robbi\ImpExpNL\Domain\ExitCode;
use Robbi\ImpExpNL\Service\ConfigurationService;
use Robbi\ImpExpNL\Service\ConfigValidationService;
use Robbi\ImpExpNL\Service\ImportLockService;
use Robbi\ImpExpNL\Service\IntegrityService;
use Robbi\ImpExpNL\Service\ProfileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

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
        private readonly ConfigurationService $configurationService,
        private readonly ConfigValidationService $configValidationService,
        private readonly ImportLockService $importLock,
        private readonly IntegrityService $integrityService,
        private readonly StorageRepository $storageRepository,
        private readonly ProfileService $profileService
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
        $this->checkRegistry();
        $this->checkRuntime();
        $this->checkProfiles();

        $success = $this->errors === 0;

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode([
                'success' => $success,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $success ? Command::SUCCESS : ExitCode::INVALID_CONFIG;
        }

        $this->render(new SymfonyStyle($input, $output));
        return $success ? Command::SUCCESS : ExitCode::INVALID_CONFIG;
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
            foreach (['tx_impexpnl_import_log', 'tx_impexpnl_lock', 'tx_impexpnl_uid_map'] as $requiredTable) {
                if ($schemaManager->tableExists($requiredTable)) {
                    $this->record($section, 'ok', "Tabelle $requiredTable vorhanden.");
                } else {
                    $this->record($section, 'error', "Tabelle $requiredTable fehlt. vendor/bin/typo3 database:updateschema ausführen.");
                }
            }
        } catch (\Exception $e) {
            $this->record($section, 'error', 'Datenbankprüfung fehlgeschlagen: ' . $e->getMessage());
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

    /**
     * Preflight-Gate: validiert die gemergte Registry gegen das echte DB-/TCA-Schema
     * (Tabellen/Felder existieren, MM-/record-Vorgaben, uid_remap) – inkl. Herkunft.
     */
    private function checkRegistry(): void
    {
        $section = 'Registry-Validierung';
        try {
            $issues = $this->configValidationService->validate(
                $this->configurationService->getRegisteredTables(),
                $this->configurationService->getLinkRewriteFields(),
                'tt_content',
                $this->configurationService->getTableSources()
            );
            if ($issues === []) {
                $this->record($section, 'ok', 'Alle registrierten Tabellen und Felder existieren im Schema.');
                return;
            }
            foreach ($issues as $issue) {
                $this->record($section, $issue['level'], $issue['message']);
            }
        } catch (\Throwable $e) {
            $this->record($section, 'error', 'Registry-Validierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Laufzeit-Preflight: FAL-Default-Storage, Import-Lock und Signatur-Modus.
     */
    private function checkRuntime(): void
    {
        $section = 'Laufzeit';

        $storageId = $this->configurationService->getFalStorageId();
        try {
            $storage = $this->storageRepository->findByUid($storageId);
            if ($storage === null) {
                $this->record($section, 'error', "FAL-Default-Storage (uid $storageId) nicht gefunden.");
            } elseif (!$storage->isOnline()) {
                $this->record($section, 'warning', "FAL-Default-Storage (uid $storageId) ist offline.");
            } else {
                $this->record($section, 'ok', "FAL-Default-Storage (uid $storageId) verfügbar.");
            }
        } catch (\Throwable $e) {
            $this->record($section, 'error', "FAL-Default-Storage (uid $storageId) nicht prüfbar: " . $e->getMessage());
        }

        try {
            if ($this->importLock->getActiveLock() === null) {
                $this->record($section, 'ok', 'Kein aktiver Import-Lock.');
            } else {
                $this->record($section, 'warning', 'Aktiver Import-Lock vorhanden – ein Import läuft oder wurde nicht sauber beendet (siehe impexpnl:status / impexpnl:unlock).');
            }
        } catch (\Throwable $e) {
            $this->record($section, 'warning', 'Lock-Status nicht ermittelbar: ' . $e->getMessage());
        }

        if ($this->integrityService->hasSigningKey()) {
            $this->record($section, 'ok', 'Signing-Key konfiguriert – HMAC-Signaturschutz aktiv.');
        } else {
            $this->record($section, 'info', 'Kein Signing-Key – Integrität nur per Prüfsumme (ohne Signatur).');
        }
    }

    /**
     * Preflight für hinterlegte Import-Profile: parsen/validieren und – falls ein
     * Profil einen Ziel-Workspace setzt – dessen Existenz prüfen.
     */
    private function checkProfiles(): void
    {
        $section = 'Profile';
        $names = $this->profileService->listProfiles();
        if ($names === []) {
            $this->record($section, 'info', 'Keine Import-Profile vorhanden (optional).');
            return;
        }

        foreach ($names as $name) {
            try {
                $profile = $this->profileService->loadProfile($name);
                $this->record($section, 'ok', "Profil '$name' ist gültig.");
                $workspace = $profile['workspace'];
                if ($workspace > 0 && !$this->workspaceExists($workspace)) {
                    $this->record($section, 'error', "Profil '$name': Ziel-Workspace $workspace existiert nicht.");
                }
            } catch (\Throwable $e) {
                $this->record($section, 'error', "Profil '$name' ungültig: " . $e->getMessage());
            }
        }
    }

    private function workspaceExists(int $uid): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
            // Workspaces-Extension nicht installiert -> nicht als Fehler werten.
            if (!in_array('sys_workspace', $connection->createSchemaManager()->listTableNames(), true)) {
                return true;
            }
            return (int)$connection->count('uid', 'sys_workspace', ['uid' => $uid]) > 0;
        } catch (\Throwable) {
            return true;
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
