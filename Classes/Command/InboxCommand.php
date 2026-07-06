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

use Robbi\ImpExpNL\Service\ConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'impexpnl:inbox',
    description: 'Lists incoming transfer packages in the inbox (does NOT trigger an import).'
)]
class InboxCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configurationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Inbox directory (default: transfer.inbox_path from the config)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = (bool)$input->getOption('json');

        $path = rtrim((string)($input->getArgument('path') ?: $this->configurationService->getInboxPath()), '/');
        if ($path === '') {
            $msg = 'No inbox directory given (transfer.inbox_path in the config or as an argument).';
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $msg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($msg);
            }
            return Command::FAILURE;
        }
        if (!is_dir($path)) {
            $msg = sprintf('Inbox directory does not exist: %s', $path);
            if ($jsonOutput) {
                $output->writeln((string)json_encode(['success' => false, 'error' => $msg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $io->error($msg);
            }
            return Command::FAILURE;
        }

        $packages = $this->scanPackages($path);

        if ($jsonOutput) {
            $output->writeln((string)json_encode(['success' => true, 'inbox' => $path, 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->title('ImpExpNL: Inbox');
        $io->text('Directory: ' . $path);
        if ($packages === []) {
            $io->text('No packages in the inbox.');
            return Command::SUCCESS;
        }
        $io->table(
            ['Package', 'Size', 'Received', 'Signature'],
            array_map(static fn(array $p): array => [
                $p['name'],
                self::humanBytes($p['size']),
                date('d.m.Y H:i', $p['mtime']),
                $p['signature'],
            ], $packages)
        );
        // Bewusst KEIN Auto-Import: der Import bleibt ein separater, expliziter Schritt.
        $io->text('Import: vendor/bin/typo3 impexpnl:import ' . $path . '/<package> <target-pid>');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name:string, size:int, mtime:int, signature:string}>
     */
    private function scanPackages(string $path): array
    {
        $packages = [];
        foreach ((array)glob($path . '/*.{tar.gz,tgz,tar}', GLOB_BRACE) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $packages[] = [
                'name' => basename($file),
                'size' => (int)filesize($file),
                'mtime' => (int)filemtime($file),
                'signature' => $this->peekSignature($file),
            ];
        }
        // Älteste zuerst (Eingangsreihenfolge).
        usort($packages, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        return $packages;
    }

    /**
     * Größen-Obergrenze für die Signatur-Vorschau: PharData lädt das Archiv in den
     * Speicher; große Bundles (mit eingepackten Assets) würden das memory_limit
     * sprengen. Oberhalb dieser Grenze wird die Spalte übersprungen ('—'). Die
     * verbindliche Signaturprüfung passiert ohnehin erst beim Import.
     */
    private const SIGNATURE_PEEK_MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Best-effort-Vorschau des Signatur-Präfixes aus der im Bundle enthaltenen
     * Export-Datei. Nur für kleine Bundles (siehe SIGNATURE_PEEK_MAX_BYTES), sonst '—'.
     */
    private function peekSignature(string $file): string
    {
        if ((int)filesize($file) > self::SIGNATURE_PEEK_MAX_BYTES) {
            return '—';
        }
        try {
            $phar = new \PharData($file);
            foreach ($phar as $entry) {
                $name = $entry->getFilename();
                if (str_ends_with($name, '.json') || str_ends_with($name, '.jsonl')) {
                    $meta = json_decode((string)file_get_contents($entry->getPathname()), true);
                    $checksum = is_array($meta) ? (string)($meta['_meta']['checksum'] ?? '') : '';
                    if ($checksum === '' && str_ends_with($name, '.jsonl')) {
                        return 'jsonl';
                    }
                    return $checksum !== '' ? explode(':', $checksum)[0] : '—';
                }
            }
        } catch (\Throwable) {
            // Kein lesbares Bundle / unbekanntes Format.
        }
        return '—';
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
