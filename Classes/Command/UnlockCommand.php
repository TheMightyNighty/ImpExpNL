<?php

declare(strict_types=1);

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
        if ($lock === null) {
            $io->success('Kein aktiver Import-Lock vorhanden.');
            return Command::SUCCESS;
        }

        $io->text(sprintf(
            'Aktiver Lock: Host %s, PID %s, gestartet %s, Alter %ds%s.',
            $lock['info']['host'] ?? '?',
            $lock['info']['pid'] ?? '?',
            $lock['info']['started'] ?? '?',
            $lock['age'],
            $lock['stale'] ? ' (veraltet)' : ''
        ));

        if (!$input->getOption('force') && !$io->confirm('Lock jetzt lösen?', false)) {
            $io->note('Abgebrochen.');
            return Command::SUCCESS;
        }

        $this->importLock->forceReleaseDbLock();
        $io->success('Import-Lock gelöst.');
        return Command::SUCCESS;
    }
}
