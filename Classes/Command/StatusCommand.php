<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
        private readonly ConnectionPool $connectionPool
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Robbi Copy: Status');

        try {
            return $this->runStatusCheck($io);
        } catch (\Exception $e) {
            $io->error('Statusprüfung fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function runStatusCheck(SymfonyStyle $io): int
    {
        // Lock-Status
        $lockFile = Environment::getVarPath() . '/robbicopy_import.lock';
        if (file_exists($lockFile)) {
            $lockData = json_decode(file_get_contents($lockFile), true);
            if (!empty($lockData['pid'])) {
                $isRunning = function_exists('posix_kill') && posix_kill((int)$lockData['pid'], 0);
                if ($isRunning) {
                    $io->warning(sprintf(
                        'Ein Import läuft gerade (PID %d, gestartet %s)',
                        $lockData['pid'], $lockData['started'] ?? '?'
                    ));
                } else {
                    $io->note('Lock-Datei vorhanden, aber Prozess ist beendet. Lock kann entfernt werden.');
                }
            }
        } else {
            $io->text('Kein aktiver Import-Lock.');
        }

        // Offene Imports
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
        $count = $qb->count('uid')
            ->from('tx_robbicopy_import_log')
            ->executeQuery()
            ->fetchOne();

        $io->text(sprintf('Rollback-fähige Imports: <info>%d</info>', $count));

        // Letzter Import
        if ($count > 0) {
            $qb2 = $this->connectionPool->getQueryBuilderForTable('tx_robbicopy_import_log');
            $last = $qb2->select('import_id', 'tstamp', 'workspace_id', 'source_file', 'delta_mode')
                ->from('tx_robbicopy_import_log')
                ->orderBy('tstamp', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($last) {
                $io->text(sprintf(
                    'Letzter Import: <info>%s</info> am %s (Workspace %d, %s)',
                    $last['import_id'],
                    date('d.m.Y H:i', (int)$last['tstamp']),
                    $last['workspace_id'],
                    $last['delta_mode'] ? 'Delta' : 'Voll'
                ));
            }
        }

        // Transaktionslog
        $logFile = Environment::getVarPath() . '/log/robbicopy_transactions.log';
        if (file_exists($logFile)) {
            $io->text(sprintf('Transaktionslog: %s (%s)',
                $logFile,
                $this->formatBytes(filesize($logFile))
            ));
        }

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
