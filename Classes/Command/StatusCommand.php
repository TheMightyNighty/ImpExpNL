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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'impexpnl:status',
    description: 'Shows the current status: open imports, lock, last activity.'
)]
class StatusCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ImportLockService $importLock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the status as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->gatherStatus();
        } catch (\Exception $e) {
            if ($input->getOption('json')) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $io->error('Status check failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(['success' => true] + $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $this->render($io, $status);
        return Command::SUCCESS;
    }

    private function gatherStatus(): array
    {
        $activeLock = $this->importLock->getActiveLock();
        $lock = ['active' => false, 'stale' => false, 'pid' => null, 'host' => null, 'started' => null, 'ageSeconds' => null];
        if ($activeLock !== null) {
            $lock = [
                'active' => true,
                'stale' => $activeLock['stale'],
                'pid' => $activeLock['info']['pid'] ?? null,
                'host' => $activeLock['info']['host'] ?? null,
                'started' => $activeLock['info']['started'] ?? null,
                'ageSeconds' => $activeLock['age'],
            ];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_impexpnl_import_log');
        $count = (int)$qb->count('uid')->from('tx_impexpnl_import_log')->executeQuery()->fetchOne();

        $last = null;
        if ($count > 0) {
            $qb2 = $this->connectionPool->getQueryBuilderForTable('tx_impexpnl_import_log');
            $row = $qb2->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode')
                ->from('tx_impexpnl_import_log')
                ->orderBy('tstamp', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if ($row) {
                $last = [
                    'importId' => (string)$row['import_id'],
                    'tstamp' => (int)$row['tstamp'],
                    'date' => date('c', (int)$row['tstamp']),
                    'workspaceId' => (int)$row['workspace_id'],
                    'sourceFile' => (string)$row['source_file'],
                    'delta' => (bool)$row['delta_mode'],
                ];
            }
        }

        $logFile = Environment::getVarPath() . '/log/impexpnl_transactions.log';

        return [
            'lock' => $lock,
            'rollbackableImports' => $count,
            'lastImport' => $last,
            'transactionLog' => file_exists($logFile) ? ['path' => $logFile, 'bytes' => (int)filesize($logFile)] : null,
        ];
    }

    private function render(SymfonyStyle $io, array $status): void
    {
        $io->title('ImpExpNL: status');

        $lock = $status['lock'];
        if ($lock['active'] && $lock['stale']) {
            $io->warning(sprintf(
                'Stale import lock (host %s, PID %s, age %ds). Release it with impexpnl:unlock.',
                $lock['host'] ?? '?',
                $lock['pid'] ?? '?',
                (int)$lock['ageSeconds']
            ));
        } elseif ($lock['active']) {
            $io->note(sprintf('Import lock active (host %s, PID %s, started %s).', $lock['host'] ?? '?', $lock['pid'] ?? '?', $lock['started'] ?? '?'));
        } else {
            $io->text('No active import lock.');
        }

        $io->text(sprintf('Rollback-capable imports: <info>%d</info>', $status['rollbackableImports']));

        if ($status['lastImport']) {
            $last = $status['lastImport'];
            $io->text(sprintf(
                'Last import: <info>%s</info> on %s (workspace %d, %s)',
                $last['importId'],
                date('d.m.Y H:i', $last['tstamp']),
                $last['workspaceId'],
                $last['delta'] ? 'delta' : 'full'
            ));
        }

        if ($status['transactionLog']) {
            $io->text(sprintf('Transaction log: %s (%s)', $status['transactionLog']['path'], $this->formatBytes($status['transactionLog']['bytes'])));
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
