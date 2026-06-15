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
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Anzahl der Einträge', 20)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Historie maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int)$input->getOption('limit');
        $jsonOutput = (bool)$input->getOption('json');

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
        $rows = $qb->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode', 'uid_map')
            ->from('tx_robbicopy_import_log')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $imports = array_map(static function (array $row): array {
            $uidMap = json_decode((string)$row['uid_map'], true) ?: [];
            return [
                'importId' => (string)$row['import_id'],
                'tstamp' => (int)$row['tstamp'],
                'date' => date('c', (int)$row['tstamp']),
                'delta' => (bool)$row['delta_mode'],
                'workspaceId' => (int)$row['workspace_id'],
                'pages' => count($uidMap['pages'] ?? []),
                'tt_content' => count($uidMap['tt_content'] ?? []),
                'sourceFile' => (string)$row['source_file'],
            ];
        }, $rows);

        if ($jsonOutput) {
            $output->writeln((string)json_encode(['success' => true, 'imports' => $imports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if (empty($imports)) {
            $io->text('Keine Imports vorhanden.');
            return Command::SUCCESS;
        }

        $io->title('Robbi Copy: Import-Historie');
        $io->table(
            ['Import-ID', 'Datum', 'Modus', 'Workspace', 'Records', 'Datei'],
            array_map(static fn(array $i): array => [
                $i['importId'],
                date('d.m.Y H:i', $i['tstamp']),
                $i['delta'] ? 'Delta' : 'Voll',
                'WS ' . $i['workspaceId'],
                $i['pages'] . ' S / ' . $i['tt_content'] . ' I',
                basename($i['sourceFile']),
            ], $imports)
        );
        $io->text('Rollback: ddev exec vendor/bin/typo3 robbicopy:undo <Import-ID>');

        return Command::SUCCESS;
    }
}
