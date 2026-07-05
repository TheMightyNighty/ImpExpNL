<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\ImpExpNL\Domain\StreamingManifestReader;
use Robbi\ImpExpNL\Service\JsonlStreamReader;

/**
 * Sichert die Streaming-Garantien des JSONL-Lesemodells: pages/tt_content werden
 * records-weise geliefert, die relationalen Aux-Daten enthalten sie NICHT.
 */
class StreamingManifestReaderTest extends TestCase
{
    private string $file;
    private StreamingManifestReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'impexpnl_smr_') ?: '';
        $lines = [
            json_encode(['_meta' => ['source_id' => 'src', 'checksum' => 'hmac-sha256:k:abc']]),
            json_encode(['_t' => 'pages', '_r' => ['uid' => 1, 'title' => 'Start']]),
            json_encode(['_t' => 'pages', '_r' => ['uid' => 2, 'title' => 'Zwei']]),
            json_encode(['_t' => 'tt_content', '_r' => ['uid' => 10, 'header' => 'H']]),
            json_encode(['_t' => 'sys_file_reference', '_r' => ['uid' => 5, 'identifier' => '/x.jpg', 'storage' => 1]]),
            json_encode(['_t' => 'assets', '_r' => ['storage' => 1, 'identifier' => '/x.jpg', 'sha256' => 'aa']]),
            json_encode(['_t' => 'sys_redirect', '_r' => ['uid' => 7, 'target' => 't3://page?uid=1']]),
        ];
        file_put_contents($this->file, implode("\n", $lines) . "\n");
        $this->reader = new StreamingManifestReader(new JsonlStreamReader(), $this->file, ['source_id' => 'src', 'checksum' => 'hmac-sha256:k:abc']);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    #[Test]
    public function metaAccessors(): void
    {
        self::assertSame('src', $this->reader->getSourceId());
        self::assertSame('hmac-sha256:k:abc', $this->reader->getChecksum());
        self::assertTrue($this->reader->hasPages());
    }

    #[Test]
    public function eachRecordStreamsLazily(): void
    {
        $gen = $this->reader->eachRecord('pages');
        self::assertInstanceOf(\Generator::class, $gen);
        self::assertSame([1, 2], array_column(iterator_to_array($gen, false), 'uid'));
    }

    #[Test]
    public function collectUidsAndCount(): void
    {
        self::assertSame([1, 2], $this->reader->collectUids('pages'));
        self::assertSame(1, $this->reader->count('tt_content'));
    }

    #[Test]
    public function auxiliaryDataExcludesStreamedBulkTables(): void
    {
        $aux = $this->reader->getAuxiliaryData();
        self::assertArrayNotHasKey('pages', $aux, 'pages darf nicht materialisiert werden');
        self::assertArrayNotHasKey('tt_content', $aux, 'tt_content darf nicht materialisiert werden');
        self::assertArrayHasKey('sys_redirect', $aux);
        self::assertArrayHasKey('sys_file_reference', $aux);
        self::assertArrayHasKey('assets', $aux);
    }

    #[Test]
    public function relationalAccessors(): void
    {
        self::assertSame(5, $this->reader->getFileReferences()[0]['uid']);
        self::assertSame('/x.jpg', $this->reader->getAssets()[0]['identifier']);
    }
}
