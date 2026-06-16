<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\ImpExpNL\Domain\UidMap;

class UidMapTest extends TestCase
{
    #[Test]
    public function setAndGetRoundtrip(): void
    {
        $map = new UidMap();
        $map->set('pages', 5, 42);

        self::assertSame(42, $map->get('pages', 5));
        self::assertTrue($map->has('pages', 5));
        self::assertNull($map->get('pages', 99));
        self::assertFalse($map->has('pages', 99));
    }

    #[Test]
    public function forTableReturnsEntriesOrEmpty(): void
    {
        $map = UidMap::fromArray(['tt_content' => [1 => 10, 2 => 20]]);
        self::assertSame([1 => 10, 2 => 20], $map->forTable('tt_content'));
        self::assertSame([], $map->forTable('pages'));
    }

    #[Test]
    public function isEmptyReflectsContent(): void
    {
        self::assertTrue((new UidMap())->isEmpty());
        self::assertTrue(UidMap::fromArray(['pages' => [], 'tt_content' => []])->isEmpty());

        $map = new UidMap();
        $map->set('pages', 1, 2);
        self::assertFalse($map->isEmpty());
    }

    #[Test]
    public function toArrayMirrorsState(): void
    {
        $map = UidMap::fromArray(['pages' => [1 => 2]]);
        $map->set('pages', 3, 4);
        self::assertSame(['pages' => [1 => 2, 3 => 4]], $map->toArray());
    }
}
