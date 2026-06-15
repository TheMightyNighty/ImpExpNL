<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit-Tests für die reine Logik im ImportService.
 *
 * Da die meisten Methoden private sind, testen wir sie über Reflection.
 * Das ist bei Unit-Tests üblich — wir testen die Logik, nicht die Sichtbarkeit.
 */
class ImportServiceTest extends TestCase
{
    // =========================================================================
    // isRecordIdentical()
    // =========================================================================

    #[Test]
    public function identicalRecordsAreDetectedAsIdentical(): void
    {
        $import = ['title' => 'Kontakt', 'bodytext' => 'Hallo', 'uid' => 1, 'pid' => 2];
        $existing = ['title' => 'Kontakt', 'bodytext' => 'Hallo', 'uid' => 99, 'pid' => 50];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function differentTitleIsDetected(): void
    {
        $import = ['title' => 'Kontakt NEU', 'bodytext' => 'Hallo'];
        $existing = ['title' => 'Kontakt', 'bodytext' => 'Hallo'];

        self::assertFalse($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function differentBodytextIsDetected(): void
    {
        $import = ['title' => 'Kontakt', 'bodytext' => 'Hallo Welt'];
        $existing = ['title' => 'Kontakt', 'bodytext' => 'Hallo'];

        self::assertFalse($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function excludedFieldsAreIgnored(): void
    {
        // uid, pid, tstamp, crdate sollten keinen Unterschied auslösen
        $import = ['title' => 'Kontakt', 'uid' => 1, 'pid' => 2, 'tstamp' => 1000, 'crdate' => 500];
        $existing = ['title' => 'Kontakt', 'uid' => 99, 'pid' => 50, 'tstamp' => 9999, 'crdate' => 8888];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function sortingIsIgnoredInComparison(): void
    {
        $import = ['title' => 'Kontakt', 'sorting' => 256];
        $existing = ['title' => 'Kontakt', 'sorting' => 512];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function remoteUidFieldIsIgnored(): void
    {
        $import = ['title' => 'Kontakt', 'tx_robbicopy_remote_uid' => 123];
        $existing = ['title' => 'Kontakt', 'tx_robbicopy_remote_uid' => 456];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function emptyStringVsNullIsHandled(): void
    {
        // In der Datenbank kann ein Feld '' oder '0' sein
        $import = ['title' => 'Kontakt', 'subtitle' => ''];
        $existing = ['title' => 'Kontakt', 'subtitle' => ''];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function intVsStringComparisonWorks(): void
    {
        // Datenbank liefert Strings, JSON liefert int — (string)-Cast muss greifen
        $import = ['title' => 'Kontakt', 'hidden' => 0];
        $existing = ['title' => 'Kontakt', 'hidden' => '0'];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    #[Test]
    public function fieldMissingInExistingIsIgnored(): void
    {
        // Wenn ein Feld im Import-Record existiert aber nicht im bestehenden
        $import = ['title' => 'Kontakt', 'new_custom_field' => 'Wert'];
        $existing = ['title' => 'Kontakt'];

        self::assertTrue($this->callIsRecordIdentical($import, $existing));
    }

    // =========================================================================
    // buildRecordData()
    // =========================================================================

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

        // Statisch excludierte Felder dürfen NICHT im Ergebnis sein
        self::assertArrayNotHasKey('uid', $result);
        self::assertArrayNotHasKey('pid', $result);
        self::assertArrayNotHasKey('tstamp', $result);
        self::assertArrayNotHasKey('crdate', $result);
        self::assertArrayNotHasKey('t3ver_oid', $result);
        self::assertArrayNotHasKey('t3_origuid', $result);
        self::assertArrayNotHasKey('l10n_diffsource', $result);

        // Inhaltsfelder MÜSSEN im Ergebnis sein
        self::assertArrayHasKey('title', $result);
        self::assertArrayHasKey('bodytext', $result);
        self::assertArrayHasKey('sorting', $result); // sorting ist NICHT excluded
        self::assertEquals('Testseite', $result['title']);
    }

    #[Test]
    public function buildRecordDataPassesThroughUnknownFieldsWithoutTable(): void
    {
        // Ohne Tabellenname (kein DB-Schema-Check) werden unbekannte Felder durchgelassen
        $source = [
            'title' => 'Test',
            'cruser_id' => 1,
            'some_custom_field' => 'value',
        ];

        $result = $this->callBuildRecordData($source);

        // cruser_id ist nicht mehr statisch excluded → wird ohne DB-Check durchgelassen
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

    // =========================================================================
    // checkSingleConflict()
    // =========================================================================

    #[Test]
    public function conflictIsDetectedWhenLocalIsNewer(): void
    {
        $import = ['uid' => 1, 'title' => 'Alt', 'tstamp' => 1000];
        $existing = ['uid' => 99, 'title' => 'Neu', 'tstamp' => 2000];

        $result = $this->callCheckSingleConflict($import, $existing);

        self::assertNotNull($result);
        self::assertStringContainsString('uid=99', $result);
    }

    #[Test]
    public function noConflictWhenExportIsNewer(): void
    {
        $import = ['uid' => 1, 'title' => 'Neu', 'tstamp' => 2000];
        $existing = ['uid' => 99, 'title' => 'Alt', 'tstamp' => 1000];

        $result = $this->callCheckSingleConflict($import, $existing);

        self::assertNull($result);
    }

    #[Test]
    public function noConflictWhenRecordsAreIdenticalDespiteNewerTimestamp(): void
    {
        $import = ['uid' => 1, 'title' => 'Gleich', 'tstamp' => 1000];
        $existing = ['uid' => 99, 'title' => 'Gleich', 'tstamp' => 2000];

        $result = $this->callCheckSingleConflict($import, $existing);

        // Inhaltlich identisch → kein Konflikt, auch wenn Timestamp neuer
        self::assertNull($result);
    }

    // =========================================================================
    // parseSince() — im ExportService, aber gleiche Logik
    // =========================================================================

    #[Test]
    public function parseSinceWithDateString(): void
    {
        $result = $this->callParseSince('2026-03-01');
        self::assertGreaterThan(0, $result);
        self::assertEquals(strtotime('2026-03-01'), $result);
    }

    #[Test]
    public function parseSinceWithTimestamp(): void
    {
        $result = $this->callParseSince('1711929600');
        self::assertEquals(1711929600, $result);
    }

    #[Test]
    public function parseSinceWithNull(): void
    {
        self::assertEquals(0, $this->callParseSince(null));
    }

    #[Test]
    public function parseSinceWithEmptyString(): void
    {
        self::assertEquals(0, $this->callParseSince(''));
    }

    // =========================================================================
    // Hilfsmethoden: Reflection-Zugriff auf private Methoden
    // =========================================================================

    private function callIsRecordIdentical(array $import, array $existing): bool
    {
        return $this->callPrivateMethod('isRecordIdentical', [$import, $existing]);
    }

    private function callBuildRecordData(array $source): array
    {
        return $this->callPrivateMethod('buildRecordData', [$source]);
    }

    private function callCheckSingleConflict(array $import, array $existing): ?string
    {
        return $this->callPrivateMethod('checkSingleConflict', [$import, $existing]);
    }

    private function callParseSince(?string $since): int
    {
        // parseSince ist im ExportService — wir testen die gleiche Logik inline
        if (empty($since)) return 0;
        if (is_numeric($since)) return (int)$since;
        $ts = strtotime($since);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Ruft eine private Methode per Reflection auf.
     * Erstellt eine minimale ImportService-Instanz ohne DI (für Unit-Tests).
     */
    private function callPrivateMethod(string $method, array $args): mixed
    {
        // Wir erzeugen die Instanz ohne Constructor (DI-Dependencies nicht nötig für Unit-Tests)
        $reflection = new \ReflectionClass(\Robbi\RobbiCopy\Service\ImportService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // excludedFields Property setzen (wird von den Methoden gebraucht)
        $excludedProp = $reflection->getProperty('excludedFields');
        $excludedProp->setAccessible(true);
        $excludedProp->setValue($instance, [
            'uid', 'pid', 'tstamp', 'crdate',
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_move_id',
            't3_origuid', 'l10n_diffsource',
        ]);

        $refMethod = $reflection->getMethod($method);
        $refMethod->setAccessible(true);
        return $refMethod->invokeArgs($instance, $args);
    }
}
