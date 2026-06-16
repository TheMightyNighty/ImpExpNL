<?php

declare(strict_types=1);

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
                throw new \RuntimeException("Export-Datei konnte nicht geschrieben werden: $filePath");
            }
        }

        $baseDir = dirname($filePath);
        $this->writeAssetsList($data, $baseDir);
        $this->writeBrokenLinksReport($data, $baseDir);
        if ($csv) {
            $this->writeCsv($data, $baseDir);
        }

        $this->logger->info('Export abgeschlossen', [
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
            throw new \RuntimeException("Export-Datei konnte nicht geschrieben werden: $filePath");
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
            $this->logger->warning('Asset-Liste konnte nicht geschrieben werden: ' . $dir . '/impexpnl_assets.txt');
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
        $this->logger->info('CSV-Export geschrieben', ['dir' => $baseDir]);
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
