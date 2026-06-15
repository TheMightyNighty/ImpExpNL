<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\RobbiCopy\Service\IntegrityService;

/**
 * Unit-Tests für den Integritäts-/Manipulationsschutz.
 */
class IntegrityServiceTest extends TestCase
{
    private IntegrityService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Sicherstellen, dass kein Signing-Key aus der Umgebung übernommen wird.
        putenv('ROBBICOPY_SIGNING_KEY');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['robbi_copy']['signingKey']);
        $this->subject = new IntegrityService();
    }

    #[Test]
    public function signAndVerifyRoundtripSucceeds(): void
    {
        $data = $this->sampleData();
        $checksum = $this->subject->sign($data);

        self::assertStringStartsWith('sha256:', $checksum);
        self::assertTrue($this->subject->verify($data, $checksum));
    }

    #[Test]
    public function verifyFailsWhenPagesAreTampered(): void
    {
        $data = $this->sampleData();
        $checksum = $this->subject->sign($data);

        $data['pages'][0]['title'] = 'Manipuliert';
        self::assertFalse($this->subject->verify($data, $checksum));
    }

    #[Test]
    public function verifyFailsWhenFileReferencesAreTampered(): void
    {
        // #1: Prüfsumme deckt den GESAMTEN Datenblock ab, nicht nur pages + tt_content.
        $data = $this->sampleData();
        $checksum = $this->subject->sign($data);

        $data['sys_file_reference'][0]['identifier'] = '/evil/payload.pdf';
        self::assertFalse($this->subject->verify($data, $checksum));
    }

    #[Test]
    public function verifyIsIndependentOfKeyOrder(): void
    {
        $data = $this->sampleData();
        $checksum = $this->subject->sign($data);

        // Gleiche Daten, andere Schlüsselreihenfolge → gleiche Prüfsumme.
        $reordered = $this->sampleData();
        $reordered['tt_content'][0] = array_reverse($reordered['tt_content'][0], true);
        self::assertTrue($this->subject->verify($reordered, $checksum));
    }

    #[Test]
    public function metaBlockIsIgnoredForChecksum(): void
    {
        $data = $this->sampleData();
        $checksum = $this->subject->sign($data);

        $data['_meta'] = ['export_date' => 'irrelevant', 'checksum' => $checksum];
        self::assertTrue($this->subject->verify($data, $checksum));
    }

    #[Test]
    public function hmacIsUsedWhenSigningKeyIsConfigured(): void
    {
        putenv('ROBBICOPY_SIGNING_KEY=geheim');
        $subject = new IntegrityService();
        $data = $this->sampleData();

        $checksum = $subject->sign($data);
        self::assertStringStartsWith('hmac-sha256:', $checksum);
        self::assertTrue($subject->verify($data, $checksum));

        // Ohne Schlüssel kann eine HMAC-Signatur nicht verifiziert werden.
        putenv('ROBBICOPY_SIGNING_KEY');
        self::assertFalse((new IntegrityService())->verify($data, $checksum));
    }

    #[Test]
    public function legacyChecksumIsStillAccepted(): void
    {
        $data = $this->sampleData();
        $legacy = hash('sha256', json_encode($data['pages']) . json_encode($data['tt_content']));

        self::assertTrue($this->subject->verify($data, $legacy));
    }

    private function sampleData(): array
    {
        return [
            'pages' => [['uid' => 1, 'title' => 'Start']],
            'tt_content' => [['uid' => 10, 'header' => 'Hallo', 'bodytext' => 'Welt']],
            'sys_file_reference' => [['uid' => 5, 'identifier' => '/user_upload/bild.jpg']],
        ];
    }
}
