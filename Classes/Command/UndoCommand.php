<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Service\RollbackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'robbicopy:undo',
    description: 'Macht einen Import vollständig rückgängig.'
)]
class UndoCommand extends Command
{
    public function __construct(
        private readonly RollbackService $rollbackService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'importId',
                InputArgument::OPTIONAL,
                'Die ID des Imports (z. B. 20260326_123000_a1b2c3). Wenn leer, wird der letzte Import rückgängig gemacht.'
            )
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Nur Vorschau, kein Löschen')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ohne Sicherheitsabfrage ausführen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importId = $input->getArgument('importId');
        $dryRun = (bool)$input->getOption('dry-run');
        $force = (bool)$input->getOption('force');

        $io->title('Robbi Copy: Rollback');

        try {
            $preview = $this->rollbackService->preview($importId);

            $io->text(sprintf('Import:     %s (%s)', $preview['importId'], $preview['date']));
            $io->text(sprintf('Quelldatei: %s', $preview['sourceFile']));
            $io->text(sprintf(
                'Entfernt:   %d Seiten, %d Inhalte (zzgl. FAL- und Registry-Einträge)',
                $preview['counts']['pages'],
                $preview['counts']['tt_content']
            ));

            if (!empty($preview['modified'])) {
                $io->warning(sprintf(
                    '%d Record(s) wurden nach dem Import lokal bearbeitet. Diese Änderungen gehen beim Rollback verloren:',
                    count($preview['modified'])
                ));
                $io->listing($preview['modified']);
            }

            if ($dryRun) {
                $io->note('Dry-Run: Es wurde nichts gelöscht.');
                return Command::SUCCESS;
            }

            if (!$force && !$io->confirm('Rollback jetzt ausführen?', false)) {
                $io->note('Abgebrochen.');
                return Command::SUCCESS;
            }

            $this->rollbackService->runRollback($preview['importId']);
            $io->success('Rollback erfolgreich abgeschlossen.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Fehler beim Rollback: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
