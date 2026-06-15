<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Functional;

use Robbi\RobbiCopy\Service\ExportService;
use Robbi\RobbiCopy\Service\IntegrityService;

/**
 * Erzeugt synthetische Export-Daten für Entwicklungs-/Last-Tests, ohne dass
 * eine echte Quell-Instanz nötig ist. Reines Dev-Werkzeug (nicht ausgeliefert).
 */
final class TestDataGenerator
{
    /**
     * Baut ein vollständiges Export-Array (inkl. gültiger Prüfsumme).
     *
     * @return array<string, mixed>
     */
    public static function build(int $pageCount, int $contentPerPage, int $branching = 5): array
    {
        $pageCount = max(1, $pageCount);
        $branching = max(2, $branching);

        $pages = [];
        for ($uid = 1; $uid <= $pageCount; $uid++) {
            $pid = $uid === 1 ? 0 : (int)(($uid - 2) / $branching) + 1;
            $pages[] = [
                'uid' => $uid,
                'pid' => $pid,
                'title' => 'Testseite ' . $uid,
                'doktype' => 1,
                'sys_language_uid' => 0,
                'l10n_parent' => 0,
                'sorting' => $uid * 256,
                'slug' => '/testseite-' . $uid,
                'hidden' => 0,
                'deleted' => 0,
            ];
        }

        $ttContent = [];
        $contentUid = 0;
        foreach ($pages as $page) {
            for ($n = 0; $n < $contentPerPage; $n++) {
                $contentUid++;
                // Querverweis auf eine andere Seite, damit Link-Rewriting beansprucht wird.
                $linkTarget = (($contentUid % $pageCount) + 1);
                $ttContent[] = [
                    'uid' => $contentUid,
                    'pid' => $page['uid'],
                    'CType' => 'text',
                    'colPos' => 0,
                    'sys_language_uid' => 0,
                    'l18n_parent' => 0,
                    'sorting' => ($n + 1) * 256,
                    'header' => 'Element ' . $contentUid,
                    'bodytext' => '<p>Inhalt ' . $contentUid . ' mit <a href="t3://page?uid=' . $linkTarget . '">Link</a>.</p>',
                    'hidden' => 0,
                    'deleted' => 0,
                ];
            }
        }

        $data = ['pages' => $pages, 'tt_content' => $ttContent];

        $data['_meta'] = [
            'export_version' => ExportService::VERSION,
            'export_format' => IntegrityService::FORMAT_VERSION,
            'export_date' => date('c'),
            'source_host' => 'testdata-generator',
            'record_counts' => [
                'pages' => count($pages),
                'tt_content' => count($ttContent),
            ],
            'checksum' => (new IntegrityService())->sign($data),
        ];

        return $data;
    }

    /**
     * Schreibt das Export-Array als JSON oder JSONL.
     *
     * @param array<string, mixed> $data
     */
    public static function writeFile(array $data, string $path, bool $jsonl = false): void
    {
        if ($jsonl) {
            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Datei nicht schreibbar: $path");
            }
            fwrite($handle, (string)json_encode(['_meta' => $data['_meta'] ?? []], JSON_UNESCAPED_UNICODE) . "\n");
            foreach ($data as $table => $value) {
                if ($table === '_meta' || !is_array($value)) {
                    continue;
                }
                foreach ($value as $record) {
                    fwrite($handle, (string)json_encode(['_t' => $table, '_r' => $record], JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
            fclose($handle);
            return;
        }

        file_put_contents($path, (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
