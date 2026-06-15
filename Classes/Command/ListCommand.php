<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'robbicopy:list',
    description: 'Zeigt die Import-Historie (rollback-fähige Imports).'
)]
class ListCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Anzahl der Einträge', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int)$input->getOption('limit');

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
        $rows = $qb->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode', 'uid_map')
            ->from('tx_robbicopy_import_log')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        if (empty($rows)) {
            $io->text('Keine Imports vorhanden.');
            return Command::SUCCESS;
        }

        $io->title('Robbi Copy: Import-Historie');

        $tableRows = [];
        foreach ($rows as $row) {
            $uidMap = json_decode($row['uid_map'], true) ?: [];
            $pageCount = count($uidMap['pages'] ?? []);
            $contentCount = count($uidMap['tt_content'] ?? []);

            $tableRows[] = [
                $row['import_id'],
                date('d.m.Y H:i', (int)$row['tstamp']),
                $row['delta_mode'] ? 'Delta' : 'Voll',
                'WS ' . $row['workspace_id'],
                $pageCount . ' S / ' . $contentCount . ' I',
                basename($row['source_file']),
            ];
        }

        $io->table(
            ['Import-ID', 'Datum', 'Modus', 'Workspace', 'Records', 'Datei'],
            $tableRows
        );

        $io->text('Rollback: ddev exec vendor/bin/typo3 robbicopy:undo <Import-ID>');

        return Command::SUCCESS;
    }
}
