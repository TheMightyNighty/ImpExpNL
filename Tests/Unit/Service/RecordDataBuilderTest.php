<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\ImpExpNL\Service\RecordDataBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Unit-Tests für die Record-Aufbereitung (Systemfeld-Filter). Ohne Tabellenname
 * werden weder Spalten- noch Relations-Container-Abgleich durchgeführt, sodass
 * kein DB-/TCA-Zugriff nötig ist.
 */
class RecordDataBuilderTest extends TestCase
{
    private RecordDataBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecordDataBuilder(
            self::createStub(ConnectionPool::class),
            self::createStub(TcaSchemaFactory::class)
        );
    }

    #[Test]
    public function filtersExcludedSystemFields(): void
    {
        $result = $this->subject->build([
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
        ]);

        foreach (['uid', 'pid', 'tstamp', 'crdate', 't3ver_oid', 't3_origuid', 'l10n_diffsource'] as $excluded) {
            self::assertArrayNotHasKey($excluded, $result);
        }
        self::assertSame('Testseite', $result['title']);
        self::assertArrayHasKey('bodytext', $result);
        self::assertArrayHasKey('sorting', $result);
    }

    #[Test]
    public function passesThroughUnknownFieldsWithoutTable(): void
    {
        $result = $this->subject->build([
            'title' => 'Test',
            'cruser_id' => 1,
            'some_custom_field' => 'value',
        ]);

        self::assertArrayHasKey('cruser_id', $result);
        self::assertArrayHasKey('some_custom_field', $result);
    }

    #[Test]
    public function handlesEmptyInput(): void
    {
        self::assertSame([], $this->subject->build([]));
    }
}
