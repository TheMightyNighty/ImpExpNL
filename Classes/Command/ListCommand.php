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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'impexpnl:list',
    description: 'Shows the import history (rollback-capable imports).'
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
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of entries', 20)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the history as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int)$input->getOption('limit');
        $jsonOutput = (bool)$input->getOption('json');

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_impexpnl_import_log');
        $rows = $qb->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode', 'uid_map')
            ->from('tx_impexpnl_import_log')
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
            $io->text('No imports present.');
            return Command::SUCCESS;
        }

        $io->title('ImpExpNL: import history');
        $io->table(
            ['Import ID', 'Date', 'Mode', 'Workspace', 'Records', 'File'],
            array_map(static fn(array $i): array => [
                $i['importId'],
                date('d.m.Y H:i', $i['tstamp']),
                $i['delta'] ? 'Delta' : 'Full',
                'WS ' . $i['workspaceId'],
                $i['pages'] . ' P / ' . $i['tt_content'] . ' C',
                basename($i['sourceFile']),
            ], $imports)
        );
        $io->text('Rollback: ddev exec vendor/bin/typo3 impexpnl:undo <import-id>');

        return Command::SUCCESS;
    }
}
