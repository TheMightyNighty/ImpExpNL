<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\RobbiCopy\Domain\PageLinkRewriter;

class PageLinkRewriterTest extends TestCase
{
    #[Test]
    public function rewritesMappedUid(): void
    {
        $result = PageLinkRewriter::rewrite('<a href="t3://page?uid=5">x</a>', [5 => 42]);
        self::assertStringContainsString('t3://page?uid=42', $result);
    }

    #[Test]
    public function leavesUnmappedUidUntouched(): void
    {
        $result = PageLinkRewriter::rewrite('t3://page?uid=99', [5 => 42]);
        self::assertSame('t3://page?uid=99', $result);
    }

    #[Test]
    public function rewritesMultipleOccurrences(): void
    {
        $result = PageLinkRewriter::rewrite('t3://page?uid=1 und t3://page?uid=2', [1 => 10, 2 => 20]);
        self::assertSame('t3://page?uid=10 und t3://page?uid=20', $result);
    }

    #[Test]
    public function returnsTextUnchangedWithoutLinks(): void
    {
        self::assertSame('nur Text', PageLinkRewriter::rewrite('nur Text', [1 => 10]));
        self::assertSame('', PageLinkRewriter::rewrite('', [1 => 10]));
    }

    #[Test]
    public function extractsPageUids(): void
    {
        self::assertSame([1, 2], PageLinkRewriter::extractPageUids('a t3://page?uid=1 b t3://page?uid=2'));
        self::assertSame([], PageLinkRewriter::extractPageUids('keine Links'));
    }
}
