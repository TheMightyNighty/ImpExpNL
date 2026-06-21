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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validiert die (auch aus Extensions gemergte) Table-Registry gegen das
 * tatsächliche Datenbank-/TCA-Schema und meldet ungültige Tabellen/Felder.
 */
#[AsCommand(
    name: 'impexpnl:validate-config',
    description: 'Validiert die Table-Registry (inkl. Extension-Profile) gegen das DB-/TCA-Schema.'
)]
class ValidateConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly ConfigValidationService $configValidationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tables = $this->configurationService->getRegisteredTables();
        $linkFields = $this->configurationService->getLinkRewriteFields();
        $extensionFiles = $this->configurationService->getExtensionConfigFiles();
        $sources = $this->configurationService->getTableSources();

        $issues = $this->configValidationService->validate($tables, $linkFields, 'tt_content', $sources);
        $errors = array_values(array_filter($issues, static fn(array $i): bool => $i['level'] === 'error'));
        $warnings = array_values(array_filter($issues, static fn(array $i): bool => $i['level'] === 'warning'));
        $success = $errors === [];

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode([
                'success' => $success,
                'tables' => count($tables),
                'extensionProfiles' => array_keys($extensionFiles),
                'errors' => $errors,
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $success ? Command::SUCCESS : ExitCode::INVALID_CONFIG;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('ImpExpNL: Konfigurationsprüfung');
        $io->text(sprintf('%d Tabelle(n) registriert.', count($tables)));
        if ($extensionFiles !== []) {
            $io->text('Extension-Profile: ' . implode(', ', array_keys($extensionFiles)));
        }

        if ($warnings !== []) {
            $io->warning(array_map(static fn(array $i): string => $i['message'], $warnings));
        }
        if ($errors !== []) {
            $io->error(array_map(static fn(array $i): string => $i['message'], $errors));
            return ExitCode::INVALID_CONFIG;
        }

        $io->success('Konfiguration gültig – alle registrierten Tabellen und Felder existieren.');
        return Command::SUCCESS;
    }
}
