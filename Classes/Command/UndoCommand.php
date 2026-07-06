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
use Robbi\ImpExpNL\Exception\ImpExpException;
use Robbi\ImpExpNL\Service\ImportLockService;
use Robbi\ImpExpNL\Service\RollbackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'impexpnl:undo',
    description: 'Fully rolls back an import.'
)]
class UndoCommand extends Command
{
    public function __construct(
        private readonly RollbackService $rollbackService,
        private readonly ImportLockService $importLock
    ) {
        parent::__construct();
    }

    /**
     * Rollback unter dem Import-Lock ausführen: Ein Undo parallel zu einem
     * LAUFENDEN Import wäre destruktiv (löscht Records, die der Import gerade
     * referenziert). Läuft ein Import, bricht acquire() mit Exit-Code 3 ab;
     * ein hängender Lock ist zuerst per impexpnl:unlock zu lösen.
     */
    private function runRollbackLocked(string $importId, bool $force): void
    {
        $lock = $this->importLock->acquire();
        try {
            $this->rollbackService->runRollback($importId, $force);
        } finally {
            $this->importLock->release($lock);
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'importId',
                InputArgument::OPTIONAL,
                'The ID of the import (e.g. 20260326_123000_a1b2c3). If empty, the last import is rolled back.'
            )
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Preview only, no deletion')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run without a confirmation prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importId = $input->getArgument('importId');
        $dryRun = (bool)$input->getOption('dry-run');
        $force = (bool)$input->getOption('force');
        $jsonOutput = (bool)$input->getOption('json');

        try {
            $preview = $this->rollbackService->preview($importId);

            if ($jsonOutput) {
                if ($dryRun) {
                    $output->writeln((string)json_encode(['success' => true, 'dryRun' => true] + $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    return Command::SUCCESS;
                }
                $this->runRollbackLocked($preview['importId'], $force);
                $output->writeln((string)json_encode(['success' => true, 'dryRun' => false, 'rolledBack' => true] + $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }

            $io->title('ImpExpNL: rollback');
            $io->text(sprintf('Import:      %s (%s)', $preview['importId'], $preview['date']));
            $io->text(sprintf('Source file: %s', $preview['sourceFile']));
            $io->text(sprintf(
                'Removes:     %d pages, %d content elements (plus FAL and registry entries)',
                $preview['counts']['pages'],
                $preview['counts']['tt_content']
            ));

            if (!empty($preview['modified'])) {
                $io->warning(sprintf(
                    '%d record(s) were edited locally after the import. These changes will be lost on rollback:',
                    count($preview['modified'])
                ));
                $io->listing($preview['modified']);
            }

            if ($dryRun) {
                $io->note('Dry run: nothing was deleted.');
                return Command::SUCCESS;
            }

            if (!$force && !$io->confirm('Run the rollback now?', false)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }

            $this->runRollbackLocked($preview['importId'], $force);
            $io->success('Rollback completed successfully.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $exitCode = $e instanceof ImpExpException ? $e->getExitCode() : ExitCode::GENERIC;
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $e->getMessage(), 'exitCode' => $exitCode], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error('Rollback error: ' . $e->getMessage());
            }
            return $exitCode;
        }
    }
}
