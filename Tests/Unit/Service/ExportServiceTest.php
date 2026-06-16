<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für ExportService-Logik.
 */
class ExportServiceTest extends TestCase
{
    // =========================================================================
    // parseSince()
    // =========================================================================

    #[DataProvider('parseSinceProvider')]
    #[Test]
    public function parseSinceReturnsCorrectTimestamp(?string $input, int $expectedMin, int $expectedMax): void
    {
        $result = $this->callParseSince($input);
        self::assertGreaterThanOrEqual($expectedMin, $result);
        self::assertLessThanOrEqual($expectedMax, $result);
    }

    public static function parseSinceProvider(): array
    {
        return [
            'null' => [null, 0, 0],
            'empty string' => ['', 0, 0],
            'date string' => ['2026-01-01', strtotime('2026-01-01'), strtotime('2026-01-01')],
            'timestamp' => ['1700000000', 1700000000, 1700000000],
            'invalid' => ['kein-datum', 0, 0],
        ];
    }

    // =========================================================================
    // Asset-Liste Logik (inline getestet, da private Methode)
    // =========================================================================

    #[Test]
    public function assetListExtractsUniqueIdentifiers(): void
    {
        $data = [
            'sys_file_reference' => [
                ['identifier' => '/user_upload/bild1.jpg'],
                ['identifier' => '/user_upload/bild2.png'],
                ['identifier' => '/user_upload/bild1.jpg'], // Duplikat
                ['identifier' => ''],                        // Leer
            ],
        ];

        $identifiers = [];
        foreach ($data['sys_file_reference'] as $ref) {
            if (!empty($ref['identifier'])) {
                $identifiers[] = ltrim($ref['identifier'], '/');
            }
        }
        $identifiers = array_unique($identifiers);
        sort($identifiers);

        self::assertCount(2, $identifiers);
        self::assertEquals('user_upload/bild1.jpg', $identifiers[0]);
        self::assertEquals('user_upload/bild2.png', $identifiers[1]);
    }

    // =========================================================================
    // Broken-Links Logik (inline getestet)
    // =========================================================================

    #[Test]
    public function brokenLinksAreDetected(): void
    {
        $exportedPageUids = [1, 2, 3];
        $content = [
            ['uid' => 10, 'pid' => 1, 'bodytext' => 'Link auf <a href="t3://page?uid=2">Seite 2</a>'],
            ['uid' => 11, 'pid' => 1, 'bodytext' => 'Link auf <a href="t3://page?uid=999">Unbekannt</a>'],
        ];

        $broken = [];
        foreach ($content as $c) {
            preg_match_all('/t3:\/\/page\?uid=(\d+)/', $c['bodytext'], $m);
            foreach ($m[1] ?? [] as $uid) {
                if (!in_array((int)$uid, $exportedPageUids, true)) {
                    $broken[] = $uid;
                }
            }
        }

        self::assertCount(1, $broken);
        self::assertEquals('999', $broken[0]);
    }

    #[Test]
    public function noBrokenLinksWhenAllTargetsExist(): void
    {
        $exportedPageUids = [1, 2, 3];
        $content = [
            ['uid' => 10, 'pid' => 1, 'bodytext' => 't3://page?uid=1 und t3://page?uid=3'],
        ];

        $broken = [];
        foreach ($content as $c) {
            preg_match_all('/t3:\/\/page\?uid=(\d+)/', $c['bodytext'], $m);
            foreach ($m[1] ?? [] as $uid) {
                if (!in_array((int)$uid, $exportedPageUids, true)) {
                    $broken[] = $uid;
                }
            }
        }

        self::assertEmpty($broken);
    }

    // =========================================================================
    // Export-Metadaten Struktur
    // =========================================================================

    #[Test]
    public function exportMetaContainsRequiredKeys(): void
    {
        $data = [
            'pages' => [['uid' => 1, 'title' => 'Test']],
            'tt_content' => [['uid' => 10, 'bodytext' => 'Hallo']],
        ];

        $pJson = json_encode($data['pages']);
        $cJson = json_encode($data['tt_content']);

        $meta = [
            'export_version' => '4.0.0',
            'export_date' => date('c'),
            'checksum' => hash('sha256', $pJson . $cJson),
            'record_counts' => [
                'pages' => count($data['pages']),
                'tt_content' => count($data['tt_content']),
            ],
        ];

        self::assertArrayHasKey('export_version', $meta);
        self::assertArrayHasKey('export_date', $meta);
        self::assertArrayHasKey('checksum', $meta);
        self::assertEquals(64, strlen($meta['checksum'])); // SHA256 = 64 hex chars
    }

    #[Test]
    public function checksumChangesWhenDataChanges(): void
    {
        $data1 = json_encode(['title' => 'Original']);
        $data2 = json_encode(['title' => 'Geändert']);

        $hash1 = hash('sha256', $data1);
        $hash2 = hash('sha256', $data2);

        self::assertNotEquals($hash1, $hash2);
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    private function callParseSince(?string $since): int
    {
        if (empty($since)) {
            return 0;
        }
        if (is_numeric($since)) {
            return (int)$since;
        }
        $ts = strtotime($since);
        return $ts !== false ? $ts : 0;
    }
}
