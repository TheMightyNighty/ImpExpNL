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
use Robbi\ImpExpNL\Service\ManifestLoaderService;
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
        private readonly ProfileService $profileService,
        private readonly DryRunPreviewRenderer $previewRenderer
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
            ->addOption('transfer-profile', 't', InputOption::VALUE_OPTIONAL, 'Transfer-Profil aus der YAML-Config (Umgebungs-Bindung + Konflikt-Policy)')
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
        // getFileAbsFileName (= relativ zum Public-Verzeichnis, wie beim Export).
        // Absolute Pfade werden hier nur durchgereicht – die verbindliche Prüfung
        // (realpath + Projektverzeichnis-Containment) macht ImportService::loadAndValidateJson;
        // Dateien außerhalb des Projektverzeichnisses werden dort abgewiesen.
        $file = GeneralUtility::getFileAbsFileName((string)$file) ?: (string)$file;

        // Große .json-Dateien werden komplett in den Speicher geparst — sichtbar
        // warnen und JSONL empfehlen (die verbindliche Prüfung inkl. memory_limit-
        // Guard macht der ManifestLoaderService).
        if (!$jsonOutput
            && str_ends_with($file, '.json')
            && is_file($file)
            && (int)filesize($file) > ManifestLoaderService::LARGE_JSON_WARN_BYTES
        ) {
            $io->warning(sprintf(
                'Große JSON-Importdatei (%d MB): .json wird vollständig in den Speicher geparst. '
                . 'Für Exporte dieser Größe das streamende JSONL-Format verwenden (Export mit --jsonl).',
                (int)round((int)filesize($file) / 1048576)
            ));
        }

        $options['dryRun'] = (bool)$input->getOption('dry-run');
        // Feld-Diff über die eingebaute Symfony-Verbosity (-v) statt eigener Option
        // (Letztere kollidiert in Symfony 7 / TYPO3 v14 mit dem globalen --verbose).
        $options['verbose'] = $output->isVerbose();
        if ($input->getOption('transfer-profile')) {
            $options['transferProfile'] = (string)$input->getOption('transfer-profile');
        }

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
            $assetsMissing = (int)($result['stats']['assets_missing'] ?? 0);
            $isDryRun = !empty($result['dryRun']);

            // Im Dry-Run kommt die Fehlmenge aus dem Preflight, beim echten Import aus
            // den übersprungenen Referenzen.
            if ($isDryRun) {
                $assetsMissing = count($result['assets']['missing'] ?? []);
            }

            // Exit-Code differenziert: DataHandler-Fehler haben Vorrang (generischer
            // Fehler), sonst signalisieren fehlende Ziel-Assets Exit-Code 7 – auch im
            // Dry-Run, damit er als CI-Preflight-Gate taugt.
            $exitCode = ExitCode::OK;
            if ($errors > 0) {
                $exitCode = ExitCode::GENERIC;
            } elseif ($assetsMissing > 0) {
                $exitCode = ExitCode::ASSETS_MISSING;
            }

            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => $exitCode === ExitCode::OK] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                if ($isDryRun) {
                    $this->previewRenderer->renderDryRunPreview($io, $output, $result);
                    if (isset($result['assets'])) {
                        $this->previewRenderer->renderAssetPreflight($io, $result['assets']);
                    }
                    $io->success('Dry-Run abgeschlossen — keine Änderungen geschrieben.');
                } elseif ($errors > 0) {
                    $io->warning(sprintf('Import abgeschlossen, aber %d DataHandler-Fehler aufgetreten (siehe Log).', $errors));
                } elseif ($assetsMissing > 0) {
                    $io->warning(sprintf('Import abgeschlossen, aber %d referenzierte Datei(en) fehlen auf dem Ziel.', $assetsMissing));
                } else {
                    $io->success('Import abgeschlossen.');
                }
                if ($exitCode !== ExitCode::OK) {
                    $this->renderExitGuidance($io, $exitCode);
                }
            }

            return $exitCode;
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
            $this->renderExitGuidance($io, $exitCode);
        }
        return $exitCode;
    }

    /**
     * Konkrete, handlungsorientierte Hilfe je Exit-Code – damit ein Code ≠ 0 dem
     * Operator sagt, was als Nächstes zu tun ist (statt nur „fehlgeschlagen").
     */
    private function renderExitGuidance(SymfonyStyle $io, int $exitCode): void
    {
        $hints = match ($exitCode) {
            ExitCode::INVALID_CONFIG => [
                'Konfiguration/Profil prüfen: vendor/bin/typo3 impexpnl:validate-config',
                'Konflikt-Strategie muss overwrite, skip, ask oder abort sein.',
            ],
            ExitCode::LOCK_ACTIVE => [
                'Ein anderer Import hält den Lock. Läuft kein Import mehr, Lock lösen: vendor/bin/typo3 impexpnl:unlock',
            ],
            ExitCode::INTEGRITY => [
                'Die Importdatei wurde verändert oder die Signatur ist ohne Schlüssel nicht prüfbar.',
                'Datei neu exportieren oder IMPEXPNL_SIGNING_KEY passend zur Quelle setzen.',
            ],
            ExitCode::CONFLICTS => [
                'Konflikte erkannt und conflict=abort. Mit --conflict=skip oder --conflict=overwrite erneut ausführen,',
                'oder zunächst --dry-run -v für den Feld-Diff.',
            ],
            ExitCode::PARTIAL_ROLLBACK => [
                'Teilimport wurde automatisch zurückgerollt. Ursache im Log prüfen und Import wiederholen.',
                'Falls kein Auto-Rollback: vendor/bin/typo3 impexpnl:undo <importId>.',
            ],
            ExitCode::ASSETS_MISSING => [
                'Referenzierte Dateien fehlen auf dem Ziel; die betroffenen FAL-Referenzen wurden übersprungen.',
                'Dateien aus impexpnl_assets.txt übertragen (z. B. rsync) und den Import erneut ausführen –',
                'oder den Transport "bundle" nutzen, der die Dateien selbst mitliefert.',
            ],
            default => [
                'Details stehen im TYPO3-Log. Vorab mit --dry-run prüfen.',
            ],
        };
        $io->note(array_merge([sprintf('Nächste Schritte (Exit-Code %d):', $exitCode)], $hints));
    }
}
