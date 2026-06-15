<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Service\RollbackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        $this->addArgument(
            'importId',
            InputArgument::OPTIONAL,
            'Die ID des Imports (z. B. 20260326_123000). Wenn leer, wird der letzte Import rückgängig gemacht.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importId = $input->getArgument('importId');

        $io->title('Robbi Copy: Rollback');

        try {
            $this->rollbackService->runRollback($importId);
            $io->success('Rollback erfolgreich abgeschlossen.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Fehler beim Rollback: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
