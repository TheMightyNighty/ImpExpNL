<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Domain\ConflictStrategy;
use Robbi\RobbiCopy\Service\ImportService;
use Robbi\RobbiCopy\Service\ProfileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'robbicopy:import', description: 'Importiert Seiten und Inhalte aus einer JSON-Datei.')]
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
            ->addOption('conflict', null, InputOption::VALUE_OPTIONAL, 'Konflikt-Strategie: overwrite, skip, ask', 'overwrite')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Feld-Diff bei Änderungen anzeigen')
            ->addOption('target-workspace', 'w', InputOption::VALUE_OPTIONAL, 'Ziel-Workspace (0=Live)', 0)
            ->addOption('profile', 'p', InputOption::VALUE_OPTIONAL, 'Import-Profil laden (aus var/robbicopy_profiles/)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis maschinenlesbar als JSON ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Profil laden oder Argumente verwenden
        $profileName = $input->getOption('profile');
        if ($profileName) {
            $profile = $this->profileService->loadProfile($profileName);
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
            $targetPid = (int)$input->getArgument('targetPid');

            if (!$file || !$targetPid) {
                $io->error('Entweder --profile oder <file> und <targetPid> angeben.');
                return Command::FAILURE;
            }

            $options = [
                'workspaceId' => (int)$input->getOption('target-workspace'),
                'deltaMode' => (bool)$input->getOption('delta'),
                'conflict' => $input->getOption('conflict'),
            ];
        }

        $options['dryRun'] = (bool)$input->getOption('dry-run');
        $options['verbose'] = (bool)$input->getOption('verbose');
        $jsonOutput = (bool)$input->getOption('json');

        // Konflikt-Strategie früh validieren (ungültige Werte sollen nicht still wie overwrite wirken).
        try {
            $options['conflict'] = ConflictStrategy::fromInput($options['conflict'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Interaktiver Ask-Modus
        if ($options['conflict'] === ConflictStrategy::Ask) {
            $options['onConflictAsk'] = function (array $info) use ($io): bool {
                return $io->confirm('Konflikt: ' . $info['message'] . ' Trotzdem überschreiben?', false);
            };
        }

        $progressBar = null;
        if (!$jsonOutput) {
            $io->title('Robbi Copy: Import');
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
            $result = $this->importService->runImport($file, $targetPid, $options);
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
