<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die in ImportService verbliebene Logik (buildRecordData).
 * Die Vergleichs-/Konfliktlogik wird in ConflictResolverTest geprüft.
 */
class ImportServiceTest extends TestCase
{
    #[Test]
    public function buildRecordDataFiltersExcludedFields(): void
    {
        $source = [
            'uid' => 123,
            'pid' => 456,
            'tstamp' => 1234567890,
            'crdate' => 1234567890,
            'title' => 'Testseite',
            'bodytext' => 'Inhalt',
            'sorting' => 256,
            't3ver_oid' => 0,
            't3_origuid' => 0,
            'l10n_diffsource' => 'serialized_data',
        ];

        $result = $this->callBuildRecordData($source);

        self::assertArrayNotHasKey('uid', $result);
        self::assertArrayNotHasKey('pid', $result);
        self::assertArrayNotHasKey('tstamp', $result);
        self::assertArrayNotHasKey('crdate', $result);
        self::assertArrayNotHasKey('t3ver_oid', $result);
        self::assertArrayNotHasKey('t3_origuid', $result);
        self::assertArrayNotHasKey('l10n_diffsource', $result);

        self::assertArrayHasKey('title', $result);
        self::assertArrayHasKey('bodytext', $result);
        self::assertArrayHasKey('sorting', $result);
        self::assertEquals('Testseite', $result['title']);
    }

    #[Test]
    public function buildRecordDataPassesThroughUnknownFieldsWithoutTable(): void
    {
        $source = [
            'title' => 'Test',
            'cruser_id' => 1,
            'some_custom_field' => 'value',
        ];

        $result = $this->callBuildRecordData($source);

        self::assertArrayHasKey('cruser_id', $result);
        self::assertArrayHasKey('some_custom_field', $result);
    }

    #[Test]
    public function buildRecordDataHandlesEmptyInput(): void
    {
        $result = $this->callBuildRecordData([]);
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    private function callBuildRecordData(array $source): array
    {
        $reflection = new \ReflectionClass(\Robbi\ImpExpNL\Service\ImportService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $excludedProp = $reflection->getProperty('excludedFields');
        $excludedProp->setAccessible(true);
        $excludedProp->setValue($instance, \Robbi\ImpExpNL\Domain\SystemFields::EXCLUDED);

        $method = $reflection->getMethod('buildRecordData');
        $method->setAccessible(true);
        return $method->invokeArgs($instance, [$source]);
    }
}
