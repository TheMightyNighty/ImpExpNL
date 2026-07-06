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
    description: 'Checks whether all prerequisites for ImpExpNL are met.'
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
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as machine-readable JSON');
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
        $section = 'Database';
        try {
            $schemaManager = $this->connectionPool->getConnectionForTable('tx_impexpnl_import_log')->createSchemaManager();
            foreach (['tx_impexpnl_import_log', 'tx_impexpnl_lock', 'tx_impexpnl_uid_map'] as $requiredTable) {
                if ($schemaManager->tableExists($requiredTable)) {
                    $this->record($section, 'ok', "Table $requiredTable present.");
                } else {
                    $this->record($section, 'error', "Table $requiredTable missing. Run vendor/bin/typo3 database:updateschema.");
                }
            }
        } catch (\Exception $e) {
            $this->record($section, 'error', 'Database check failed: ' . $e->getMessage());
        }
    }

    private function checkFilesystem(): void
    {
        $section = 'Filesystem';
        $varPath = Environment::getVarPath();

        if (is_writable($varPath)) {
            $this->record($section, 'ok', 'var/ directory writable: ' . $varPath);
        } else {
            $this->record($section, 'error', 'var/ directory not writable: ' . $varPath);
        }

        $logDir = $varPath . '/log';
        if (is_dir($logDir) && is_writable($logDir)) {
            $this->record($section, 'ok', 'Log directory writable: ' . $logDir);
        } elseif (!is_dir($logDir)) {
            $this->record($section, 'warning', 'Log directory does not exist yet (created on first import): ' . $logDir);
        } else {
            $this->record($section, 'error', 'Log directory not writable: ' . $logDir);
        }

        $profileDir = $varPath . '/impexpnl_profiles';
        if (is_dir($profileDir)) {
            $profiles = glob($profileDir . '/*.yaml');
            $this->record($section, 'ok', 'Profile directory present: ' . count($profiles ?: []) . ' profile(s) found.');
        } else {
            $this->record($section, 'info', 'Profile directory does not exist (optional): ' . $profileDir);
        }
    }

    private function checkConfiguration(): void
    {
        $section = 'Configuration';
        try {
            $config = $this->configurationService->getConfig();
            if (!empty($config)) {
                $this->record($section, 'ok', 'imp_exp_nl.yaml loaded.');
            } else {
                $this->record($section, 'warning', 'imp_exp_nl.yaml is empty.');
            }

            $tables = $this->configurationService->getRegisteredTables();
            if (!empty($tables)) {
                $this->record($section, 'ok', 'Table registry: ' . count($tables) . ' table(s) registered.');
                foreach ($tables as $tableName => $tableConfig) {
                    foreach ($this->validateTableConfig((string)$tableName, $tableConfig) as $problem) {
                        $this->record($section, 'error', $problem);
                    }
                }
            } else {
                $this->record($section, 'info', 'No tables in the registry (optional).');
            }

            $linkFields = $config['import']['link_rewrite']['fields'] ?? [];
            if (!empty($linkFields)) {
                $this->record($section, 'ok', 'Link rewriting active for: ' . implode(', ', $linkFields));
            }
        } catch (\Exception $e) {
            $this->record($section, 'error', 'Invalid YAML configuration: ' . $e->getMessage());
        }
    }

    private function checkExtensionScan(): void
    {
        $section = 'Extension scan';
        $extConfigs = $this->configurationService->getExtensionConfigFiles();
        if (!empty($extConfigs)) {
            foreach ($extConfigs as $packageKey => $file) {
                $this->record($section, 'ok', $packageKey . ' provides Configuration/ImpExpNL.yaml');
            }
        } else {
            $this->record($section, 'info', 'No extensions with their own ImpExpNL.yaml found (optional).');
        }
    }

    /**
     * Preflight-Gate: validiert die gemergte Registry gegen das echte DB-/TCA-Schema
     * (Tabellen/Felder existieren, MM-/record-Vorgaben, uid_remap) – inkl. Herkunft.
     */
    private function checkRegistry(): void
    {
        $section = 'Registry validation';
        try {
            $config = $this->configurationService->getConfig();
            $issues = array_merge(
                $this->configValidationService->validate(
                    $this->configurationService->getRegisteredTables(),
                    $this->configurationService->getLinkRewriteFields(),
                    'tt_content',
                    $this->configurationService->getTableSources()
                ),
                $this->configValidationService->validateTransferProfiles(
                    (array)($config['transferProfiles'] ?? []),
                    (array)($config['assetTransports'] ?? [])
                )
            );
            if ($issues === []) {
                $this->record($section, 'ok', 'All registered tables and fields exist in the schema.');
                return;
            }
            foreach ($issues as $issue) {
                $this->record($section, $issue['level'], $issue['message']);
            }
        } catch (\Throwable $e) {
            $this->record($section, 'error', 'Registry validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Laufzeit-Preflight: FAL-Default-Storage, Import-Lock und Signatur-Modus.
     */
    private function checkRuntime(): void
    {
        $section = 'Runtime';

        $storageId = $this->configurationService->getFalStorageId();
        try {
            $storage = $this->storageRepository->findByUid($storageId);
            if ($storage === null) {
                $this->record($section, 'error', "FAL default storage (uid $storageId) not found.");
            } elseif (!$storage->isOnline()) {
                $this->record($section, 'warning', "FAL default storage (uid $storageId) is offline.");
            } else {
                $this->record($section, 'ok', "FAL default storage (uid $storageId) available.");
            }
        } catch (\Throwable $e) {
            $this->record($section, 'error', "FAL default storage (uid $storageId) could not be checked: " . $e->getMessage());
        }

        try {
            if ($this->importLock->getActiveLock() === null) {
                $this->record($section, 'ok', 'No active import lock.');
            } else {
                $this->record($section, 'warning', 'Active import lock present – an import is running or was not shut down cleanly (see impexpnl:status / impexpnl:unlock).');
            }
        } catch (\Throwable $e) {
            $this->record($section, 'warning', 'Lock status could not be determined: ' . $e->getMessage());
        }

        $hasKey = $this->integrityService->hasSigningKey();
        $requireSignature = $this->configurationService->isSignatureRequired();
        if ($hasKey && $requireSignature) {
            $this->record($section, 'ok', 'Signing key + import.require_signature active – only HMAC-signed import files are accepted.');
        } elseif ($hasKey) {
            $this->record($section, 'warning', 'Signing key configured, but import.require_signature is not active – files WITHOUT a checksum would be imported unchecked. Recommendation: import.require_signature: true.');
        } elseif ($requireSignature) {
            $this->record($section, 'error', 'import.require_signature is active, but no signing key is configured – every import will fail. Set IMPEXPNL_SIGNING_KEY.');
        } else {
            $this->record($section, 'info', 'No signing key – integrity via checksum only (no signature).');
        }
    }

    /**
     * Preflight für hinterlegte Import-Profile: parsen/validieren und – falls ein
     * Profil einen Ziel-Workspace setzt – dessen Existenz prüfen.
     */
    private function checkProfiles(): void
    {
        $section = 'Profiles';
        $names = $this->profileService->listProfiles();
        if ($names === []) {
            $this->record($section, 'info', 'No import profiles present (optional).');
            return;
        }

        foreach ($names as $name) {
            try {
                $profile = $this->profileService->loadProfile($name);
                $this->record($section, 'ok', "Profile '$name' is valid.");
                $workspace = $profile['workspace'];
                if ($workspace > 0 && !$this->workspaceExists($workspace)) {
                    $this->record($section, 'error', "Profile '$name': target workspace $workspace does not exist.");
                }
            } catch (\Throwable $e) {
                $this->record($section, 'error', "Profile '$name' invalid: " . $e->getMessage());
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
        $io->title('ImpExpNL: system check');

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

        $io->section('Result');
        if ($this->errors === 0 && $this->warnings === 0) {
            $io->success('All checks passed. ImpExpNL is ready to use.');
        } elseif ($this->errors === 0) {
            $io->warning("$this->warnings notice(s), no errors. ImpExpNL is functional.");
        } else {
            $io->error("$this->errors error(s), $this->warnings notice(s). The errors must be fixed before use.");
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
            $problems[] = "Registry '$tableName': invalid type '$type' (allowed: mm, record).";
            return $problems;
        }

        if ($type === 'mm') {
            if (isset($config['match_tables']) && !is_array($config['match_tables'])) {
                $problems[] = "Registry '$tableName': match_tables must be a list.";
            }
            if (isset($config['category_match']) && $config['category_match'] !== 'path') {
                $problems[] = "Registry '$tableName': category_match only supports 'path'.";
            }
        } else {
            if (isset($config['rewrite_links']) && !is_array($config['rewrite_links'])) {
                $problems[] = "Registry '$tableName': rewrite_links must be a list.";
            }
            if (isset($config['uid_remap']) && !is_bool($config['uid_remap'])) {
                $problems[] = "Registry '$tableName': uid_remap must be true/false.";
            }
        }

        return $problems;
    }
}
