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

use Robbi\ImpExpNL\Domain\ExitCode;
use Robbi\ImpExpNL\Exception\ImpExpException;
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

#[AsCommand(name: 'impexpnl:export', description: 'Exports a page tree as a JSON file.')]
class ExportCommand extends Command
{
    public function __construct(private readonly ExportService $exportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('startPid', InputArgument::REQUIRED, 'Start PID of the page tree')
            ->addArgument('outputFile', InputArgument::REQUIRED, 'Target path for the JSON file')
            ->addOption('depth', null, InputOption::VALUE_OPTIONAL, 'Maximum depth (0 = unlimited)', 0)
            ->addOption('include-hidden', null, InputOption::VALUE_NONE, 'Include hidden/disabled records')
            ->addOption('pages', null, InputOption::VALUE_OPTIONAL, 'Export only specific PIDs (comma-separated)')
            ->addOption('exclude-pages', null, InputOption::VALUE_OPTIONAL, 'Exclude PIDs (comma-separated)')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Only records changed since a date (Y-m-d)')
            ->addOption('content-types', null, InputOption::VALUE_OPTIONAL, 'Only specific CTypes (comma-separated)')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Additionally generate CSV files for table comparison')
            ->addOption('jsonl', null, InputOption::VALUE_NONE, 'Memory-friendly JSONL format (one record line per entry)')
            ->addOption('transport', null, InputOption::VALUE_OPTIONAL, 'Asset transport: external (default) or bundle (self-contained .tar.gz)', 'external')
            ->addOption('bundle-baseline', null, InputOption::VALUE_OPTIONAL, 'Incremental bundle: asset manifest of the target (impexpnl_assets.json) – files already identical in content there are not packed')
            ->addOption('outbox', null, InputOption::VALUE_OPTIONAL, 'Target directory for --transport=push (overrides assetTransports.<name>.outbox from the config)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as machine-readable JSON');
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
            'transport' => (string)$input->getOption('transport'),
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
        if ($input->getOption('bundle-baseline')) {
            $options['transportConfig']['baseline'] = (string)$input->getOption('bundle-baseline');
        }
        if ($input->getOption('outbox')) {
            $options['transportConfig']['outbox'] = (string)$input->getOption('outbox');
        }

        $progressBar = null;
        if (!$jsonOutput) {
            $io->title('ImpExpNL: export');
            $io->text("Start PID: $startPid");

            $progressBar = new ProgressBar($output);
            $progressBar->setFormat(' [%bar%] %message%');
            $progressBar->setMessage('Starting...');
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
            $projectBase = rtrim($projectPath, '/');
            if ($resolvedDir === false || ($resolvedDir !== $projectBase && !str_starts_with($resolvedDir, $projectBase . '/'))) {
                if ($jsonOutput) {
                    $output->writeln((string)json_encode(['success' => false, 'error' => "Target path lies outside the project directory: $outputFile"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $io->error("Target path lies outside the project directory: $outputFile");
                }
                return Command::FAILURE;
            }

            $startTime = microtime(true);
            $transportResult = $this->exportService->runExport($startPid, $absolutePath, $options);
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            if ($progressBar) {
                $progressBar->setMessage('Done!');
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
                    'transport' => $transportResult,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->success(sprintf('Export written → %s', $outputFile));
                $io->text($transportResult['message']);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($progressBar) {
                $progressBar->finish();
                $output->writeln('');
            }
            $exitCode = $e instanceof ImpExpException ? $e->getExitCode() : ExitCode::GENERIC;
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $e->getMessage(), 'exitCode' => $exitCode], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($e->getMessage());
            }
            return $exitCode;
        }
    }
}
