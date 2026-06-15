<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\RobbiCopy\Domain\ExportManifest;

class ExportManifestTest extends TestCase
{
    #[Test]
    public function typedAccessorsReturnSections(): void
    {
        $manifest = ExportManifest::fromArray([
            'pages' => [['uid' => 1]],
            'tt_content' => [['uid' => 10]],
            'sys_file_reference' => [['uid' => 5]],
            'irre_relations' => [['table' => 'x']],
            '_site_config' => [['identifier' => 'main']],
            '_meta' => ['checksum' => 'sha256:abc'],
        ]);

        self::assertSame([['uid' => 1]], $manifest->getPages());
        self::assertSame([['uid' => 10]], $manifest->getTtContent());
        self::assertSame([['uid' => 5]], $manifest->getFileReferences());
        self::assertSame([['table' => 'x']], $manifest->getIrreRelations());
        self::assertSame([['identifier' => 'main']], $manifest->getSiteConfig());
        self::assertSame('sha256:abc', $manifest->getChecksum());
        self::assertTrue($manifest->hasPages());
    }

    #[Test]
    public function missingSectionsDefaultToEmpty(): void
    {
        $manifest = ExportManifest::fromArray(['pages' => [['uid' => 1]]]);

        self::assertSame([], $manifest->getTtContent());
        self::assertSame([], $manifest->getFileReferences());
        self::assertNull($manifest->getChecksum());
    }

    #[Test]
    public function hasPagesIsFalseWhenEmpty(): void
    {
        self::assertFalse(ExportManifest::fromArray([])->hasPages());
        self::assertFalse(ExportManifest::fromArray(['pages' => []])->hasPages());
    }

    #[Test]
    public function toArrayPreservesRegistryTables(): void
    {
        $raw = ['pages' => [['uid' => 1]], 'sys_redirect' => [['uid' => 7]]];
        self::assertSame($raw, ExportManifest::fromArray($raw)->toArray());
    }
}
