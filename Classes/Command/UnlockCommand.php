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

use Robbi\ImpExpNL\Service\ImportLockService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'impexpnl:unlock',
    description: 'Releases a stuck import lock.'
)]
class UnlockCommand extends Command
{
    public function __construct(
        private readonly ImportLockService $importLock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Release without confirmation')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = (bool)$input->getOption('json');

        $lock = $this->importLock->getActiveLock();
        if ($lock === null) {
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => true, 'released' => false, 'lock' => null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }
            $io->success('No active import lock present.');
            return Command::SUCCESS;
        }

        if ($jsonOutput) {
            // --json impliziert einen unbeaufsichtigten Lauf: ohne --force wird
            // nicht gelöst, sondern nur der Lock gemeldet (kein Interaktiv-Prompt).
            $released = false;
            if ($input->getOption('force')) {
                $this->importLock->forceReleaseDbLock();
                $released = true;
            }
            $output->writeln((string)json_encode(['success' => true, 'released' => $released, 'lock' => $lock], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->text(sprintf(
            'Active lock: host %s, PID %s, started %s, age %ds%s.',
            $lock['info']['host'] ?? '?',
            $lock['info']['pid'] ?? '?',
            $lock['info']['started'] ?? '?',
            $lock['age'],
            $lock['stale'] ? ' (stale)' : ''
        ));

        if (!$input->getOption('force') && !$io->confirm('Release the lock now?', false)) {
            $io->note('Aborted.');
            return Command::SUCCESS;
        }

        $this->importLock->forceReleaseDbLock();
        $io->success('Import lock released.');
        return Command::SUCCESS;
    }
}
