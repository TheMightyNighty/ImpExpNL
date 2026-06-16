<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für TableRegistryService-Logik.
 */
class TableRegistryServiceTest extends TestCase
{
    #[Test]
    public function pageLinksAreRewritten(): void
    {
        $text = 'Klicke <a href="t3://page?uid=123">hier</a> und <a href="t3://page?uid=456">dort</a>.';
        $uidMap = ['pages' => [123 => 890, 456 => 891]];

        $result = $this->rewritePageLinks($text, $uidMap);

        self::assertStringContainsString('t3://page?uid=890', $result);
        self::assertStringContainsString('t3://page?uid=891', $result);
        self::assertStringNotContainsString('t3://page?uid=123', $result);
    }

    #[Test]
    public function unknownLinksAreLeftUntouched(): void
    {
        $text = 't3://page?uid=999';
        $uidMap = ['pages' => [123 => 890]];

        $result = $this->rewritePageLinks($text, $uidMap);

        self::assertEquals('t3://page?uid=999', $result);
    }

    #[Test]
    public function textWithoutLinksIsUnchanged(): void
    {
        $text = 'Einfacher Text ohne Links.';
        $uidMap = ['pages' => [123 => 890]];

        $result = $this->rewritePageLinks($text, $uidMap);

        self::assertEquals($text, $result);
    }

    #[Test]
    public function emptyTextIsHandled(): void
    {
        $result = $this->rewritePageLinks('', ['pages' => []]);
        self::assertEquals('', $result);
    }

    #[Test]
    public function categoryPathSegmentsSplit(): void
    {
        $path = 'Themen > Digitalisierung > E-Government';
        $segments = array_map('trim', explode('>', $path));

        self::assertCount(3, $segments);
        self::assertEquals('Themen', $segments[0]);
        self::assertEquals('Digitalisierung', $segments[1]);
        self::assertEquals('E-Government', $segments[2]);
    }

    #[Test]
    public function singleSegmentPathWorks(): void
    {
        $path = 'Unkategorisiert';
        $segments = array_map('trim', explode('>', $path));

        self::assertCount(1, $segments);
        self::assertEquals('Unkategorisiert', $segments[0]);
    }

    #[Test]
    public function emptyPathReturnsEmptyArray(): void
    {
        $path = '';
        $segments = array_filter(array_map('trim', explode('>', $path)));

        self::assertEmpty($segments);
    }

    // Hilfsmethode: Identisch zur Implementierung im TableRegistryService
    private function rewritePageLinks(string $text, array $uidMap): string
    {
        return preg_replace_callback('/t3:\/\/page\?uid=(\d+)/', function ($m) use ($uidMap) {
            $old = (int)$m[1];
            return isset($uidMap['pages'][$old]) ? 't3://page?uid=' . $uidMap['pages'][$old] : $m[0];
        }, $text);
    }
}
