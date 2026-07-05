<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\ImpExpNL\Service\JsonlStreamReader;

/**
 * Unit-Tests für den zeilenweisen JSONL-Leser (Grundlage des streamenden Imports).
 */
class JsonlStreamReaderTest extends TestCase
{
    private string $file;
    private JsonlStreamReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new JsonlStreamReader();
        $this->file = tempnam(sys_get_temp_dir(), 'impexpnl_jsonl_') ?: '';
        $lines = [
            json_encode(['_meta' => ['source_id' => 'a', 'checksum' => 'hmac-sha256:x']]),
            json_encode(['_t' => 'pages', '_r' => ['uid' => 1, 'title' => 'Start']]),
            '',
            json_encode(['_t' => 'tt_content', '_r' => ['uid' => 10, 'header' => 'Hallo']]),
            json_encode(['_t' => 'pages', '_r' => ['uid' => 2, 'title' => 'Zweite']]),
            json_encode(['_t' => 'sys_redirect', '_r' => ['uid' => 5]]),
        ];
        file_put_contents($this->file, implode("\n", $lines) . "\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    #[Test]
    public function readMetaReturnsFirstMetaLine(): void
    {
        self::assertSame('a', $this->reader->readMeta($this->file)['source_id']);
    }

    #[Test]
    public function streamTableYieldsOnlyMatchingTable(): void
    {
        $pages = iterator_to_array($this->reader->streamTable($this->file, 'pages'), false);
        self::assertCount(2, $pages);
        self::assertSame([1, 2], array_column($pages, 'uid'));
        self::assertSame('Start', $pages[0]['title']);
    }

    #[Test]
    public function collectUidsReturnsIntsOnly(): void
    {
        self::assertSame([1, 2], $this->reader->collectUids($this->file, 'pages'));
        self::assertSame([10], $this->reader->collectUids($this->file, 'tt_content'));
    }

    #[Test]
    public function countCountsRecordsWithoutHoldingThem(): void
    {
        self::assertSame(2, $this->reader->count($this->file, 'pages'));
        self::assertSame(0, $this->reader->count($this->file, 'nichtvorhanden'));
    }

    #[Test]
    public function tableNamesListsEveryTableOnce(): void
    {
        $names = $this->reader->tableNames($this->file);
        sort($names);
        self::assertSame(['pages', 'sys_redirect', 'tt_content'], $names);
    }

    #[Test]
    public function collectTablesMaterializesSelectedTables(): void
    {
        $tables = $this->reader->collectTables($this->file, ['tt_content', 'sys_redirect']);
        self::assertArrayHasKey('tt_content', $tables);
        self::assertArrayHasKey('sys_redirect', $tables);
        self::assertArrayNotHasKey('pages', $tables);
        self::assertSame(10, $tables['tt_content'][0]['uid']);
    }

    #[Test]
    public function invalidLineThrows(): void
    {
        file_put_contents($this->file, "{\"_t\":\"pages\",\"_r\":{}}\nKEIN JSON\n");
        $this->expectException(\RuntimeException::class);
        iterator_to_array($this->reader->streamTable($this->file, 'pages'), false);
    }
}
