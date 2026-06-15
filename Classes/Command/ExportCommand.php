<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Command;

use Robbi\RobbiCopy\Service\ExportService;
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

#[AsCommand(name: 'robbicopy:export', description: 'Exportiert einen Seitenbaum als JSON-Datei.')]
class ExportCommand extends Command
{
    public function __construct(private readonly ExportService $exportService) { parent::__construct(); }

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
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Zusätzlich CSV-Dateien für Tabellenvergleich erzeugen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startPid = (int)$input->getArgument('startPid');
        $outputFile = (string)$input->getArgument('outputFile');

        $options = [
            'depth' => (int)$input->getOption('depth'),
            'includeHidden' => (bool)$input->getOption('include-hidden'),
            'csv' => (bool)$input->getOption('csv'),
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

        $io->title('Robbi Copy: Export');
        $io->text("Start-PID: $startPid");

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat(' [%bar%] %message%');
        $progressBar->setMessage('Starte...');
        $progressBar->start();

        $options['onProgress'] = function (string $msg, int $cur, int $total) use ($progressBar) {
            $progressBar->setMessage("$msg ($cur)");
            $progressBar->advance();
        };

        try {
            $absolutePath = GeneralUtility::getFileAbsFileName($outputFile) ?: Environment::getProjectPath() . '/' . ltrim($outputFile, '/');

            // OWASP A01: Sicherstellen, dass der Zielpfad innerhalb des Projekts liegt
            $projectPath = Environment::getProjectPath();
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $resolvedDir = realpath($dir);
            if ($resolvedDir === false || !str_starts_with($resolvedDir, $projectPath)) {
                $io->error("Zielpfad liegt außerhalb des Projektverzeichnisses: $outputFile");
                return Command::FAILURE;
            }

            $this->exportService->runExport($startPid, $absolutePath, $options);

            $progressBar->setMessage('Fertig!');
            $progressBar->finish();
            $output->writeln('');

            $data = json_decode(file_get_contents($absolutePath), true);
            $io->success(sprintf('Export: %d Seiten, %d Inhalte → %s',
                count($data['pages'] ?? []), count($data['tt_content'] ?? []), $outputFile));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $progressBar->finish();
            $output->writeln('');
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
