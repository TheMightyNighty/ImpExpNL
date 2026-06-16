<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Command;

use Robbi\ImpExpNL\Service\ExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'impexpnl:export', description: 'Exportiert einen Seitenbaum als JSON-Datei.')]
class ExportCommand extends Command
{
    public function __construct(private readonly ExportService $exportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('startPid', InputArgument::REQUIRED, 'Start-PID des Seitenbaums')
            ->addArgument('outputFile', InputArgument::REQUIRED, 'Zielpfad für die JSON-Datei')
            ->addOption('depth', null, InputOption::VALUE_OPTIONAL, 'Maximale Tiefe (0 = unbegrenzt)', 0)
            ->addOption('include-hidden', null, InputOption::VALUE_NONE, 'Versteckte/deaktivierte Records einschließen')
            ->addOption('pages', null, InputOption::VALUE_OPTIONAL, 'Nur bestimmte PIDs exportieren (komma-separiert)')
            ->addOption('exclude-pages', null, InputOption::VALUE_OPTIONAL, 'PIDs ausschließen (komma-separiert)')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Nur seit Datum geänderte Records (Y-m-d)')
            ->addOption('content-types', null, InputOption::VALUE_OPTIONAL, 'Nur bestimmte CTypes (komma-separiert)')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Zusätzlich CSV-Dateien für Tabellenvergleich erzeugen')
            ->addOption('jsonl', null, InputOption::VALUE_NONE, 'Speicherschonendes JSONL-Format (eine Record-Zeile pro Eintrag)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startPid = (int)$input->getArgument('startPid');
        $outputFile = (string)$input->getArgument('outputFile');
        $jsonOutput = (bool)$input->getOption('json');

        $options = [
            'depth' => (int)$input->getOption('depth'),
            'includeHidden' => (bool)$input->getOption('include-hidden'),
            'csv' => (bool)$input->getOption('csv'),
            'jsonl' => (bool)$input->getOption('jsonl'),
        ];

        if ($input->getOption('pages')) {
            $options['pages'] = array_map('intval', explode(',', $input->getOption('pages')));
        }
        if ($input->getOption('exclude-pages')) {
            $options['excludePages'] = array_map('intval', explode(',', $input->getOption('exclude-pages')));
        }
        if ($input->getOption('since')) {
            $options['since'] = $input->getOption('since');
        }
        if ($input->getOption('content-types')) {
            $options['contentTypes'] = explode(',', $input->getOption('content-types'));
        }

        $progressBar = null;
        if (!$jsonOutput) {
            $io->title('ImpExpNL: Export');
            $io->text("Start-PID: $startPid");

            $progressBar = new ProgressBar($output);
            $progressBar->setFormat(' [%bar%] %message%');
            $progressBar->setMessage('Starte...');
            $progressBar->start();

            $options['onProgress'] = function (string $msg, int $cur, int $total) use ($progressBar) {
                $progressBar->setMessage("$msg ($cur)");
                $progressBar->advance();
            };
        }

        try {
            $absolutePath = GeneralUtility::getFileAbsFileName($outputFile) ?: Environment::getProjectPath() . '/' . ltrim($outputFile, '/');

            // Zielpfad muss innerhalb des Projektverzeichnisses liegen.
            $projectPath = Environment::getProjectPath();
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $resolvedDir = realpath($dir);
            if ($resolvedDir === false || !str_starts_with($resolvedDir, $projectPath)) {
                if ($jsonOutput) {
                    $output->writeln((string)json_encode(['success' => false, 'error' => "Zielpfad liegt außerhalb des Projektverzeichnisses: $outputFile"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $io->error("Zielpfad liegt außerhalb des Projektverzeichnisses: $outputFile");
                }
                return Command::FAILURE;
            }

            $startTime = microtime(true);
            $this->exportService->runExport($startPid, $absolutePath, $options);
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            if ($progressBar) {
                $progressBar->setMessage('Fertig!');
                $progressBar->finish();
                $output->writeln('');
            }

            if ($jsonOutput) {
                $output->writeln((string)json_encode([
                    'success' => true,
                    'outputFile' => $outputFile,
                    'bytes' => is_file($absolutePath) ? (int)filesize($absolutePath) : 0,
                    'format' => $options['jsonl'] ? 'jsonl' : 'json',
                    'durationMs' => $durationMs,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->success(sprintf('Export geschrieben → %s', $outputFile));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($progressBar) {
                $progressBar->finish();
                $output->writeln('');
            }
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($e->getMessage());
            }
            return Command::FAILURE;
        }
    }
}
