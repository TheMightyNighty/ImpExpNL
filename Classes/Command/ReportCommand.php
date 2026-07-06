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
use Robbi\ImpExpNL\Service\ReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'impexpnl:report',
    description: 'Creates or verifies a signed transfer report (audit artifact) for an import.'
)]
class ReportCommand extends Command
{
    public function __construct(
        private readonly ReportService $reportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('importId', InputArgument::OPTIONAL, 'Import ID (default: most recent import)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Write the signed report to this file (JSON)')
            ->addOption('verify', null, InputOption::VALUE_OPTIONAL, 'Verify a previously written report file instead of creating one')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the full signed report as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = (bool)$input->getOption('json');

        $verifyFile = $input->getOption('verify');
        if (is_string($verifyFile) && $verifyFile !== '') {
            return $this->verify($io, $output, $jsonOutput, $verifyFile);
        }

        $importId = $input->getArgument('importId');
        $document = $this->reportService->build(is_string($importId) ? $importId : null);
        if ($document === null) {
            $message = $importId
                ? sprintf('No import log found for import ID "%s".', (string)$importId)
                : 'No import log present.';
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($message);
            }
            return Command::FAILURE;
        }

        $encoded = (string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outputFile = $input->getOption('output');
        if (is_string($outputFile) && $outputFile !== '') {
            if (@file_put_contents($outputFile, $encoded . "\n") === false) {
                $io->error(sprintf('Report could not be written to "%s".', $outputFile));
                return Command::FAILURE;
            }
        }

        if ($jsonOutput) {
            $output->writeln($encoded);
            return Command::SUCCESS;
        }

        $report = $document['report'];
        $io->title('ImpExpNL: transfer report');
        $io->table(['Field', 'Value'], [
            ['Import ID', $report['import']['import_id']],
            ['Imported at', $report['import']['imported_at']],
            ['Mode', $report['import']['delta'] ? 'Delta' : 'Full'],
            ['Workspace', (string)$report['import']['workspace_id']],
            ['Source file', $report['import']['source_file']],
            ['Source sha256', $report['source']['available'] ? $report['source']['sha256'] : 'file no longer available'],
            ['Records total', (string)$report['records_total']],
            ['Assets placed', (string)$report['assets_placed']],
            ['Signature', $document['signature']],
        ]);
        if (!str_starts_with($document['signature'], 'hmac-sha256:')) {
            $io->note('No signing key configured — the report carries a plain checksum, not a tamper-proof signature. Set IMPEXPNL_SIGNING_KEY for audit-grade reports.');
        }
        if (is_string($outputFile) && $outputFile !== '') {
            $io->text(sprintf('Signed report written to %s — verify with: impexpnl:report --verify=%s', $outputFile, $outputFile));
        }

        return Command::SUCCESS;
    }

    private function verify(SymfonyStyle $io, OutputInterface $output, bool $jsonOutput, string $file): int
    {
        if (!is_file($file)) {
            $message = sprintf('Report file not found: %s', $file);
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($message);
            }
            return Command::FAILURE;
        }

        $document = json_decode((string)file_get_contents($file), true);
        $valid = is_array($document) && $this->reportService->verify($document);

        if ($jsonOutput) {
            $output->writeln((string)json_encode([
                'success' => $valid,
                'file' => $file,
                'importId' => is_array($document) ? ($document['report']['import']['import_id'] ?? null) : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif ($valid) {
            $io->success(sprintf('Report signature is valid (%s).', $file));
        } else {
            $io->error(sprintf('Report signature is INVALID or the file is not a report document: %s', $file));
        }

        return $valid ? Command::SUCCESS : ExitCode::INTEGRITY;
    }
}
