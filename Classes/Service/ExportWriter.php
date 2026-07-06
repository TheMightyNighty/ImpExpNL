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

namespace Robbi\ImpExpNL\Service;

use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Domain\PageLinkRewriter;

/**
 * Schreibt die Export-Ergebnisse in die Zieldateien: Hauptdatei (JSON oder
 * JSONL), Asset-Liste, Broken-Links-Report und optional CSV.
 */
class ExportWriter
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data, string $filePath, bool $jsonl, bool $csv): void
    {
        if ($jsonl || str_ends_with($filePath, '.jsonl')) {
            $this->writeJsonl($data, $filePath);
        } else {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($filePath, $json) === false) {
                throw new \RuntimeException("Export file could not be written: $filePath");
            }
        }

        $baseDir = dirname($filePath);
        $this->writeAssetsList($data, $baseDir);
        $this->writeAssetManifest($data, $baseDir);
        $this->writeBrokenLinksReport($data, $baseDir);
        if ($csv) {
            $this->writeCsv($data, $baseDir);
        }

        // Exporte können sensible Inhalte tragen (Redaktionsdaten, ggf. PII):
        // Artefakte nicht mit der Prozess-umask liegen lassen, sondern auf
        // Besitzer-Rechte beschränken.
        $this->restrictPermissions(
            $filePath,
            $baseDir . '/impexpnl_assets.txt',
            $baseDir . '/impexpnl_assets.json',
            $baseDir . '/impexpnl_broken_links.txt',
            $baseDir . '/impexpnl_pages.csv',
            $baseDir . '/impexpnl_tt_content.csv'
        );

        $this->logger->info('Export completed', [
            'pages' => count($data['pages'] ?? []),
            'tt_content' => count($data['tt_content'] ?? []),
            'file' => $filePath,
        ]);
    }

    /**
     * Zeilenweises JSONL (ein JSON-Objekt pro Zeile). Vermeidet den
     * monolithischen Encode-String und senkt den Speicher-Peak bei großen Bäumen.
     *
     * @param array<string, mixed> $data
     */
    private function writeJsonl(array $data, string $filePath): void
    {
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Export file could not be written: $filePath");
        }
        try {
            fwrite($handle, (string)json_encode(['_meta' => $data['_meta'] ?? []], JSON_UNESCAPED_UNICODE) . "\n");
            foreach ($data as $table => $value) {
                if ($table === '_meta' || !is_array($value)) {
                    continue;
                }
                foreach ($value as $record) {
                    fwrite($handle, (string)json_encode(['_t' => $table, '_r' => $record], JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeAssetsList(array $data, string $dir): void
    {
        $ids = array_unique(array_filter(array_map(fn($r) => ltrim($r['identifier'] ?? '', '/'), $data['sys_file_reference'] ?? [])));
        sort($ids);
        if (file_put_contents($dir . '/impexpnl_assets.txt', implode("\n", $ids) . "\n") === false) {
            $this->logger->warning('Asset list could not be written: ' . $dir . '/impexpnl_assets.txt');
        }
    }

    /**
     * Gehärtetes Asset-Manifest als JSON (pro Datei storage/identifier/size/sha256).
     * Ergänzt die rsync-taugliche `impexpnl_assets.txt` um Integritäts-/Transfer-Metadaten.
     *
     * @param array<string, mixed> $data
     */
    private function writeAssetManifest(array $data, string $dir): void
    {
        if (empty($data['assets']) || !is_array($data['assets'])) {
            return;
        }
        $json = json_encode($data['assets'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($dir . '/impexpnl_assets.json', $json . "\n") === false) {
            $this->logger->warning('Asset manifest could not be written: ' . $dir . '/impexpnl_assets.json');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeBrokenLinksReport(array $data, string $dir): void
    {
        $exp = array_column($data['pages'] ?? [], 'uid');
        $broken = [];
        foreach ($data['tt_content'] ?? [] as $c) {
            foreach (['bodytext', 'header_link', 'pi_flexform'] as $f) {
                if (empty($c[$f]) || !is_string($c[$f])) {
                    continue;
                }
                foreach (PageLinkRewriter::extractPageUids($c[$f]) as $uid) {
                    if (!in_array($uid, $exp, true)) {
                        $broken[] = "tt_content uid={$c['uid']}: t3://page?uid=$uid";
                    }
                }
            }
        }
        file_put_contents($dir . '/impexpnl_broken_links.txt', !empty($broken) ? implode("\n", array_unique($broken)) . "\n" : "Keine gebrochenen Links.\n");
    }

    /**
     * Flache CSV-Übersicht für den Tabellenvergleich.
     *
     * @param array<string, mixed> $data
     */
    private function writeCsv(array $data, string $baseDir): void
    {
        foreach (['pages', 'tt_content'] as $table) {
            $records = $data[$table] ?? [];
            if (empty($records)) {
                continue;
            }

            $fp = fopen($baseDir . '/impexpnl_' . $table . '.csv', 'w');
            if ($fp === false) {
                continue;
            }
            fputcsv($fp, array_map([$this, 'sanitizeCsvValue'], array_keys($records[0])));
            foreach ($records as $row) {
                fputcsv($fp, array_map(fn($v) => $this->sanitizeCsvValue(is_string($v) ? mb_substr($v, 0, 500) : $v), $row));
            }
            fclose($fp);
        }
        $this->logger->info('CSV export written', ['dir' => $baseDir]);
    }

    private function restrictPermissions(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file) && !@chmod($file, 0600)) {
                $this->logger->warning('File permissions could not be restricted: ' . $file);
            }
        }
    }

    /**
     * Schutz vor CSV-Formula-Injection: Werte mit führendem Formel-Trigger
     * (= + - @ TAB CR) werden mit einem Apostroph entschärft.
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
