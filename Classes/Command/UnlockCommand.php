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
    description: 'Löst einen hängenden Import-Lock.'
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
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Ohne Rückfrage lösen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->importLock->getActiveLock();
        $hasFileLock = $this->importLock->hasFileLock();

        // Auch einen verwaisten Datei-Lock (ohne DB-Lock) berücksichtigen – der bleibt nach
        // einem harten Crash liegen und blockiert sonst jeden Wiederanlauf.
        if ($lock === null && !$hasFileLock) {
            $io->success('Kein aktiver Import-Lock vorhanden.');
            return Command::SUCCESS;
        }

        if ($lock !== null) {
            $io->text(sprintf(
                'Aktiver Lock: Host %s, PID %s, gestartet %s, Alter %ds%s.',
                $lock['info']['host'] ?? '?',
                $lock['info']['pid'] ?? '?',
                $lock['info']['started'] ?? '?',
                $lock['age'],
                $lock['stale'] ? ' (veraltet)' : ''
            ));
        } else {
            $io->text('Verwaister Datei-Lock (kein DB-Lock) – vermutlich nach hartem Crash.');
        }

        if (!$input->getOption('force') && !$io->confirm('Lock jetzt lösen?', false)) {
            $io->note('Abgebrochen.');
            return Command::SUCCESS;
        }

        $this->importLock->forceReleaseDbLock();
        $io->success('Import-Lock gelöst (DB- und Datei-Lock).');
        return Command::SUCCESS;
    }
}
