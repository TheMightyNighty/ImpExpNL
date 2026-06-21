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

use Robbi\ImpExpNL\Domain\ConflictStrategy;
use Robbi\ImpExpNL\Domain\ExitCode;
use Robbi\ImpExpNL\Exception\ImpExpException;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\ProfileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'impexpnl:import', description: 'Importiert Seiten und Inhalte aus einer JSON-Datei.')]
class ImportCommand extends Command
{
    public function __construct(
        private readonly ImportService $importService,
        private readonly ProfileService $profileService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Pfad zur JSON-Datei (nicht nötig bei --profile)')
            ->addArgument('targetPid', InputArgument::OPTIONAL, 'Ziel-PID (nicht nötig bei --profile)')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Nur Analyse, keine Datenänderung')
            ->addOption('delta', null, InputOption::VALUE_NONE, 'Nur Änderungen importieren')
            ->addOption('conflict', null, InputOption::VALUE_OPTIONAL, 'Konflikt-Strategie: overwrite, skip, ask, abort', 'overwrite')
            ->addOption('target-workspace', 'w', InputOption::VALUE_OPTIONAL, 'Ziel-Workspace (0=Live)', 0)
            ->addOption('profile', 'p', InputOption::VALUE_OPTIONAL, 'Import-Profil laden (aus var/impexpnl_profiles/)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Profil laden oder Argumente verwenden
        $jsonOutput = (bool)$input->getOption('json');

        $profileName = $input->getOption('profile');
        if ($profileName) {
            try {
                $profile = $this->profileService->loadProfile($profileName);
            } catch (\Throwable $e) {
                return $this->fail($io, $output, $jsonOutput, $e);
            }
            $file = $profile['source_file'];
            $targetPid = $profile['target_pid'];
            $options = [
                'workspaceId' => $profile['workspace'],
                'deltaMode' => $profile['delta'],
                'conflict' => $profile['conflict'],
            ];
            $io->note("Profil '$profileName' geladen.");
        } else {
            $file = $input->getArgument('file');
            $targetPidArg = $input->getArgument('targetPid');

            // targetPid 0 (Wurzel) ist gültig – daher explizit auf null prüfen, nicht auf "falsy".
            if ($file === null || $targetPidArg === null) {
                $io->error('Entweder --profile oder <file> und <targetPid> angeben.');
                return Command::FAILURE;
            }
            $targetPid = (int)$targetPidArg;

            $options = [
                'workspaceId' => (int)$input->getOption('target-workspace'),
                'deltaMode' => (bool)$input->getOption('delta'),
                'conflict' => $input->getOption('conflict'),
            ];
        }

        // Pfad symmetrisch zum Export auflösen: relative Pfade gehen über
        // getFileAbsFileName (= relativ zum Public-Verzeichnis, wie beim Export),
        // absolute Pfade außerhalb bleiben unverändert nutzbar.
        $file = GeneralUtility::getFileAbsFileName((string)$file) ?: (string)$file;

        $options['dryRun'] = (bool)$input->getOption('dry-run');
        // Feld-Diff über die eingebaute Symfony-Verbosity (-v) statt eigener Option
        // (Letztere kollidiert in Symfony 7 / TYPO3 v14 mit dem globalen --verbose).
        $options['verbose'] = $output->isVerbose();

        // Konflikt-Strategie früh validieren (ungültige Werte sollen nicht still wie overwrite wirken).
        try {
            $options['conflict'] = ConflictStrategy::fromInput($options['conflict'] ?? null);
        } catch (\InvalidArgumentException $e) {
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($e->getMessage());
            }
            return ExitCode::INVALID_CONFIG;
        }

        // Interaktiver Ask-Modus
        if ($options['conflict'] === ConflictStrategy::Ask) {
            $options['onConflictAsk'] = function (array $info) use ($io): bool {
                return $io->confirm('Konflikt: ' . $info['message'] . ' Trotzdem überschreiben?', false);
            };
        }

        $progressBar = null;
        if (!$jsonOutput) {
            $io->title('ImpExpNL: Import');
            $io->text("Datei: $file | Ziel-PID: $targetPid");
            if ($options['dryRun']) {
                $io->note('Dry-Run.');
            }
            if ($options['deltaMode']) {
                $io->note('Delta-Modus.');
            }
            if ($options['conflict'] !== ConflictStrategy::Overwrite) {
                $io->note('Konflikt-Strategie: ' . $options['conflict']->value);
            }

            if (!$options['dryRun']) {
                $progressBar = new ProgressBar($output);
                $progressBar->setFormat(' [%bar%] %message%');
                $progressBar->setMessage('Starte...');
                $progressBar->start();
                $options['onProgress'] = function (string $msg, int $cur, int $total) use ($progressBar) {
                    $progressBar->setMessage("$msg ($cur/$total)");
                    $progressBar->advance();
                };
            }
        }

        try {
            $startTime = microtime(true);
            $result = $this->importService->runImport($file, $targetPid, $options);
            $result['durationMs'] = (int)((microtime(true) - $startTime) * 1000);
            if ($progressBar) {
                $progressBar->setMessage('Fertig!');
                $progressBar->finish();
                $output->writeln('');
            }

            $errors = (int)($result['stats']['errors'] ?? 0);

            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => $errors === 0] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif ($errors > 0) {
                $io->warning(sprintf('Import abgeschlossen, aber %d DataHandler-Fehler aufgetreten (siehe Log).', $errors));
            } else {
                $io->success('Import abgeschlossen.');
            }

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($progressBar) {
                $progressBar->finish();
                $output->writeln('');
            }
            return $this->fail($io, $output, $jsonOutput, $e);
        }
    }

    /**
     * Gibt den Fehler aus und liefert den differenzierten Exit-Code:
     * fachliche {@see ImpExpException} tragen ihren Code, alles andere ist generisch.
     */
    private function fail(SymfonyStyle $io, OutputInterface $output, bool $jsonOutput, \Throwable $e): int
    {
        $exitCode = $e instanceof ImpExpException ? $e->getExitCode() : ExitCode::GENERIC;
        if ($jsonOutput) {
            $output->writeln((string)json_encode(
                ['success' => false, 'error' => $e->getMessage(), 'exitCode' => $exitCode],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $io->error($e->getMessage());
        }
        return $exitCode;
    }
}
