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

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Konsolen-Darstellung des Dry-Run-Ergebnisses (Diff-Vorschau, Asset-Preflight).
 * Aus {@see ImportCommand} extrahiert, damit der Command die Ablaufsteuerung
 * behält und die Präsentation eine eigene, austauschbare Einheit ist.
 */
class DryRunPreviewRenderer
{
    /**
     * "Rich Terminal"-Ausgabe der Dry-Run-Vorschau. Der dekorative Block (Balken,
     * farbige Labels) wird NUR bei dekoriertem Output gerendert (interaktives TTY) – bei
     * --no-ansi/Pipe/CI/--json greift die schlichte Tabelle, damit keine ANSI-/Balken-Zeilen
     * Logs zumüllen. Es wird nichts blockiert (keine Eingabe, kein ProgressBar).
     *
     * @param array<string, mixed> $result
     */
    public function renderDryRunPreview(SymfonyStyle $io, OutputInterface $output, array $result): void
    {
        $diff = $result['diff'] ?? [];
        $preview = $result['preview'] ?? [];
        $new = (int)($diff['pages']['new'] ?? 0) + (int)($diff['tt_content']['new'] ?? 0);
        $updated = (int)($diff['pages']['changed'] ?? 0) + (int)($diff['tt_content']['changed'] ?? 0);
        $unchanged = (int)($diff['pages']['identical'] ?? 0) + (int)($diff['tt_content']['identical'] ?? 0);
        $conflicts = 0; // im Dry-Run nicht separat ermittelt
        $errors = 0;
        $assetTotal = (int)($result['assets']['total'] ?? 0);

        // Nicht-dekoriert (CI/Pipe/--no-ansi): schlichte Tabelle, keine Balken/Farben.
        if (!$output->isDecorated()) {
            $io->section('Dry run – diff preview');
            $io->table(['', 'Count'], [
                ['new', $new],
                ['updated', $updated],
                ['unchanged', $unchanged],
                ['conflicts', $conflicts],
                ['errors', $errors],
                ['assets (Manifest)', $assetTotal],
            ]);
            return;
        }

        $tv = 'unknown';
        try {
            $tv = GeneralUtility::makeInstance(Typo3Version::class)->getVersion();
        } catch (\Throwable) {
        }
        $ev = '';
        try {
            $ev = ExtensionManagementUtility::getExtensionVersion('imp_exp_nl');
        } catch (\Throwable) {
        }
        // Nur echte Release-Versionen zeigen (nicht „dev-main" o. Ä. aus Path-Repos).
        $versionLabel = preg_match('/^\d+\.\d+/', $ev) === 1 ? ' ' . $ev : '';

        $io->newLine();
        $output->writeln(sprintf(
            '  <options=bold>ImpExpNL</>%s   <fg=gray>TYPO3 %s   ·   package:</> %s',
            $versionLabel,
            $tv,
            (string)($preview['package'] ?? '')
        ));
        $io->newLine();

        if (!empty($preview['checksum'])) {
            $this->okLine($output, 'checksum verified', '<fg=gray>' . $this->shortHash((string)$preview['checksum']) . '</>');
        } else {
            $this->okLine($output, 'checksum', '<fg=gray>no signature</>');
        }
        $this->okLine($output, 'import lock', !empty($preview['lockFree']) ? 'free' : '<fg=red>active</>');
        $this->okLine($output, 'mode', 'dry-run' . (!empty($preview['delta']) ? ' + delta' : ''));

        $io->newLine();
        $output->writeln('  <fg=blue;options=bold>DIFF PREVIEW</>');
        $max = max(1, $new, $updated, $unchanged, $conflicts, $errors);
        $this->diffRow($output, '+', 'green', 'new', $new, $max);
        $this->diffRow($output, '~', 'yellow', 'updated', $updated, $max);
        $this->diffRow($output, '=', 'gray', 'unchanged', $unchanged, $max);
        $this->diffRow($output, '!', 'red', 'conflicts', $conflicts, $max);
        $this->diffRow($output, 'x', 'red', 'errors', $errors, $max);

        $samples = $diff['samples'] ?? [];
        $io->newLine();
        if (isset($samples['new'])) {
            $output->writeln(sprintf(
                '  <fg=black;bg=green> NEW </>   %-10s "%s" <fg=gray>-></> %s',
                (string)$samples['new']['table'],
                (string)$samples['new']['title'],
                (string)$samples['new']['slug']
            ));
        }
        if (isset($samples['changed'])) {
            $output->writeln(sprintf(
                '  <fg=black;bg=yellow> UPD </>   %-10s <fg=gray>uid</> %d <fg=gray>·</> %s changed',
                (string)$samples['changed']['table'],
                (int)$samples['changed']['uid'],
                (string)$samples['changed']['field']
            ));
        }
        if ($assetTotal > 0) {
            $output->writeln(sprintf(
                '  <fg=black;bg=blue> ASSET </> %-9s <fg=gray>referenced in manifest</>',
                $assetTotal . ' files'
            ));
        }
        $io->newLine();
    }

    /**
     * Asset-Preflight im Dry-Run: fehlende / inhaltlich abweichende Dateien auf dem Ziel.
     *
     * @param array{total:int, missing:list<string>, mismatch:list<string>} $assets
     */
    public function renderAssetPreflight(SymfonyStyle $io, array $assets): void
    {
        $total = $assets['total'];
        if ($total === 0) {
            return;
        }
        $missing = $assets['missing'];
        $mismatch = $assets['mismatch'];

        if ($missing === [] && $mismatch === []) {
            $io->text(sprintf('Asset preflight: %d file(s) in the manifest, all present on the target and identical in content.', $total));
            return;
        }
        if ($missing !== []) {
            $io->warning(sprintf('%d of %d asset file(s) are missing on the target — transfer them before the import (e.g. via rsync):', count($missing), $total));
            $io->listing(array_slice($missing, 0, 20));
        }
        if ($mismatch !== []) {
            $io->note(sprintf('%d asset file(s) have different content on the target — the existing file is left unchanged during import:', count($mismatch)));
            $io->listing(array_slice($mismatch, 0, 20));
        }
    }

    private function okLine(OutputInterface $output, string $label, string $value): void
    {
        $output->writeln(sprintf('  <fg=green>OK</>  %-20s %s', $label, $value));
    }

    private function diffRow(OutputInterface $output, string $symbol, string $color, string $label, int $count, int $max): void
    {
        $width = 22;
        $filled = $max > 0 ? (int)round($count / $max * $width) : 0;
        $filled = max(0, min($width, $filled));
        $bar = sprintf('<fg=%s>%s</><fg=gray>%s</>', $color, str_repeat('█', $filled), str_repeat('░', $width - $filled));
        $output->writeln(sprintf(
            '  <fg=%s>%s</>  %-12s <fg=%s>%5d</>  %s',
            $color,
            $symbol,
            $label,
            $color,
            $count,
            $bar
        ));
    }

    private function shortHash(string $checksum): string
    {
        $hash = str_contains($checksum, ':') ? substr($checksum, (int)strrpos($checksum, ':') + 1) : $checksum;
        if (strlen($hash) <= 12) {
            return $checksum;
        }
        return 'sha256:' . substr($hash, 0, 4) . '…' . substr($hash, -4);
    }
}
