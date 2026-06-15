<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Service\ImportLockService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'robbicopy:status',
    description: 'Zeigt den aktuellen Status: Offene Imports, Lock, letzte Aktivität.'
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
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Status maschinenlesbar als JSON ausgeben');
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
                $io->error('Statusprüfung fehlgeschlagen: ' . $e->getMessage());
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

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
        $count = (int)$qb->count('uid')->from('tx_robbicopy_import_log')->executeQuery()->fetchOne();

        $last = null;
        if ($count > 0) {
            $qb2 = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
            $row = $qb2->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode')
                ->from('tx_robbicopy_import_log')
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

        $logFile = Environment::getVarPath() . '/log/robbicopy_transactions.log';

        return [
            'lock' => $lock,
            'rollbackableImports' => $count,
            'lastImport' => $last,
            'transactionLog' => file_exists($logFile) ? ['path' => $logFile, 'bytes' => (int)filesize($logFile)] : null,
        ];
    }

    private function render(SymfonyStyle $io, array $status): void
    {
        $io->title('Robbi Copy: Status');

        $lock = $status['lock'];
        if ($lock['active'] && $lock['stale']) {
            $io->warning(sprintf(
                'Veralteter Import-Lock (Host %s, PID %s, Alter %ds). Mit robbicopy:unlock lösen.',
                $lock['host'] ?? '?',
                $lock['pid'] ?? '?',
                (int)$lock['ageSeconds']
            ));
        } elseif ($lock['active']) {
            $io->note(sprintf('Import-Lock aktiv (Host %s, PID %s, gestartet %s).', $lock['host'] ?? '?', $lock['pid'] ?? '?', $lock['started'] ?? '?'));
        } else {
            $io->text('Kein aktiver Import-Lock.');
        }

        $io->text(sprintf('Rollback-fähige Imports: <info>%d</info>', $status['rollbackableImports']));

        if ($status['lastImport']) {
            $last = $status['lastImport'];
            $io->text(sprintf(
                'Letzter Import: <info>%s</info> am %s (Workspace %d, %s)',
                $last['importId'],
                date('d.m.Y H:i', $last['tstamp']),
                $last['workspaceId'],
                $last['delta'] ? 'Delta' : 'Voll'
            ));
        }

        if ($status['transactionLog']) {
            $io->text(sprintf('Transaktionslog: %s (%s)', $status['transactionLog']['path'], $this->formatBytes($status['transactionLog']['bytes'])));
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
