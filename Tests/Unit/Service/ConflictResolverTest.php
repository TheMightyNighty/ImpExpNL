<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Robbi\ImpExpNL\Service\ConflictResolver;

class ConflictResolverTest extends TestCase
{
    private ConflictResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ConflictResolver(new NullLogger());
    }

    #[Test]
    public function identicalRecordsAreDetectedAsIdentical(): void
    {
        $import = ['title' => 'Kontakt', 'bodytext' => 'Hallo', 'uid' => 1, 'pid' => 2];
        $existing = ['title' => 'Kontakt', 'bodytext' => 'Hallo', 'uid' => 99, 'pid' => 50];

        self::assertTrue($this->subject->isRecordIdentical($import, $existing));
    }

    #[Test]
    public function differentTitleIsDetected(): void
    {
        self::assertFalse($this->subject->isRecordIdentical(
            ['title' => 'Kontakt NEU', 'bodytext' => 'Hallo'],
            ['title' => 'Kontakt', 'bodytext' => 'Hallo']
        ));
    }

    #[Test]
    public function excludedFieldsAreIgnored(): void
    {
        $import = ['title' => 'Kontakt', 'uid' => 1, 'pid' => 2, 'tstamp' => 1000, 'crdate' => 500];
        $existing = ['title' => 'Kontakt', 'uid' => 99, 'pid' => 50, 'tstamp' => 9999, 'crdate' => 8888];

        self::assertTrue($this->subject->isRecordIdentical($import, $existing));
    }

    #[Test]
    public function sortingAndRemoteUidAreIgnored(): void
    {
        $import = ['title' => 'Kontakt', 'sorting' => 256, 'tx_impexpnl_remote_uid' => 123];
        $existing = ['title' => 'Kontakt', 'sorting' => 512, 'tx_impexpnl_remote_uid' => 456];

        self::assertTrue($this->subject->isRecordIdentical($import, $existing));
    }

    #[Test]
    public function intVsStringComparisonWorks(): void
    {
        self::assertTrue($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'hidden' => 0],
            ['title' => 'Kontakt', 'hidden' => '0']
        ));
    }

    #[Test]
    public function fieldMissingInExistingIsIgnored(): void
    {
        self::assertTrue($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'new_custom_field' => 'Wert'],
            ['title' => 'Kontakt']
        ));
    }

    #[Test]
    public function conflictIsDetectedWhenLocalIsNewer(): void
    {
        $result = $this->subject->detectConflict(
            ['uid' => 1, 'title' => 'Alt', 'tstamp' => 1000],
            ['uid' => 99, 'title' => 'Neu', 'tstamp' => 2000]
        );

        self::assertNotNull($result);
        self::assertStringContainsString('uid=99', $result);
    }

    #[Test]
    public function noConflictWhenExportIsNewer(): void
    {
        self::assertNull($this->subject->detectConflict(
            ['uid' => 1, 'title' => 'Neu', 'tstamp' => 2000],
            ['uid' => 99, 'title' => 'Alt', 'tstamp' => 1000]
        ));
    }

    #[Test]
    public function noConflictWhenRecordsAreIdenticalDespiteNewerTimestamp(): void
    {
        self::assertNull($this->subject->detectConflict(
            ['uid' => 1, 'title' => 'Gleich', 'tstamp' => 1000],
            ['uid' => 99, 'title' => 'Gleich', 'tstamp' => 2000]
        ));
    }

    #[Test]
    public function conflictLabelFallsBackToHeaderForContent(): void
    {
        $result = $this->subject->detectConflict(
            ['uid' => 1, 'header' => 'Alt', 'tstamp' => 1000],
            ['uid' => 5, 'header' => 'Neu', 'tstamp' => 2000]
        );

        self::assertNotNull($result);
        self::assertStringContainsString('Neu', $result);
    }

    #[Test]
    public function emptyStringAndZeroAreEquivalent(): void
    {
        // DB-Default int 0 vs. leerer Export-Wert '' (z. B. fe_group) darf die
        // Delta-Idempotenz nicht brechen.
        self::assertTrue($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'fe_group' => ''],
            ['title' => 'Kontakt', 'fe_group' => '0']
        ));
        self::assertTrue($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'fe_group' => 0],
            ['title' => 'Kontakt', 'fe_group' => '']
        ));
    }

    #[Test]
    public function realEmptyVsContentIsStillDifferent(): void
    {
        self::assertFalse($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'subtitle' => ''],
            ['title' => 'Kontakt', 'subtitle' => 'Text']
        ));
    }

    #[Test]
    public function slugIsIgnoredInComparison(): void
    {
        // Slugs werden beim Import für die Ziel-Site regeneriert und sind daher
        // kein Identitätsmerkmal.
        self::assertTrue($this->subject->isRecordIdentical(
            ['title' => 'Kontakt', 'slug' => '/kontakt'],
            ['title' => 'Kontakt', 'slug' => '/unternehmen/kontakt']
        ));
    }
}
