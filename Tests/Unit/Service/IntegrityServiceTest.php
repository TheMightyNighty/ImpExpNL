<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\ImpExpNL\Service\IntegrityService;

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
        putenv('IMPEXPNL_SIGNING_KEY');
        putenv('IMPEXPNL_SIGNING_KEY_ID');
        unset(
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys']
        );
        $this->subject = new IntegrityService();
    }

    protected function tearDown(): void
    {
        putenv('IMPEXPNL_SIGNING_KEY');
        putenv('IMPEXPNL_SIGNING_KEY_ID');
        unset(
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys']
        );
        parent::tearDown();
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
        putenv('IMPEXPNL_SIGNING_KEY=geheim');
        $subject = new IntegrityService();
        $data = $this->sampleData();

        $checksum = $subject->sign($data);
        self::assertStringStartsWith('hmac-sha256:', $checksum);
        self::assertTrue($subject->verify($data, $checksum));

        // Ohne Schlüssel kann eine HMAC-Signatur nicht verifiziert werden.
        putenv('IMPEXPNL_SIGNING_KEY');
        self::assertFalse((new IntegrityService())->verify($data, $checksum));
    }

    #[Test]
    public function legacyChecksumIsStillAccepted(): void
    {
        $data = $this->sampleData();
        $legacy = hash('sha256', json_encode($data['pages']) . json_encode($data['tt_content']));

        self::assertTrue($this->subject->verify($data, $legacy));
    }

    #[Test]
    public function plainChecksumIsRejectedWhenSigningKeyIsConfigured(): void
    {
        // Downgrade-Schutz: Mit konfiguriertem Schlüssel darf eine bloße sha256-
        // Prüfsumme (von jedem neu berechenbar) NICHT mehr akzeptiert werden.
        $data = $this->sampleData();
        $plain = $this->subject->sign($data);
        self::assertStringStartsWith('sha256:', $plain);

        putenv('IMPEXPNL_SIGNING_KEY=geheim');
        try {
            self::assertFalse((new IntegrityService())->verify($data, $plain));
        } finally {
            putenv('IMPEXPNL_SIGNING_KEY');
        }
    }

    #[Test]
    public function legacyChecksumIsRejectedWhenSigningKeyIsConfigured(): void
    {
        $data = $this->sampleData();
        $legacy = hash('sha256', json_encode($data['pages']) . json_encode($data['tt_content']));

        putenv('IMPEXPNL_SIGNING_KEY=geheim');
        try {
            self::assertFalse((new IntegrityService())->verify($data, $legacy));
        } finally {
            putenv('IMPEXPNL_SIGNING_KEY');
        }
    }

    #[Test]
    public function signWithKeyIdEmbedsTheKeyId(): void
    {
        putenv('IMPEXPNL_SIGNING_KEY=schluessel-2026');
        putenv('IMPEXPNL_SIGNING_KEY_ID=k2026');
        $subject = new IntegrityService();
        $data = $this->sampleData();

        $checksum = $subject->sign($data);
        self::assertStringStartsWith('hmac-sha256:k2026:', $checksum);
        self::assertTrue($subject->verify($data, $checksum));
    }

    #[Test]
    public function rotatedKeyIsResolvedViaKeyIdFromSigningKeys(): void
    {
        // Datei wurde mit dem alten Schlüssel (k2025) signiert ...
        putenv('IMPEXPNL_SIGNING_KEY=alter-schluessel');
        putenv('IMPEXPNL_SIGNING_KEY_ID=k2025');
        $checksum = (new IntegrityService())->sign($this->sampleData());

        // ... nach der Rotation ist k2026 aktiv, k2025 als Alt-Schlüssel hinterlegt.
        putenv('IMPEXPNL_SIGNING_KEY=neuer-schluessel');
        putenv('IMPEXPNL_SIGNING_KEY_ID=k2026');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = ['k2025' => 'alter-schluessel'];

        self::assertTrue((new IntegrityService())->verify($this->sampleData(), $checksum));
    }

    #[Test]
    public function unknownKeyIdIsRejected(): void
    {
        putenv('IMPEXPNL_SIGNING_KEY=geheim');
        putenv('IMPEXPNL_SIGNING_KEY_ID=k2026');
        $subject = new IntegrityService();
        $checksum = $subject->sign($this->sampleData());

        // Zielsystem kennt die Key-ID nicht (anderer aktiver Key, keine signingKeys).
        putenv('IMPEXPNL_SIGNING_KEY_ID=anderes-system');
        self::assertFalse((new IntegrityService())->verify($this->sampleData(), $checksum));
    }

    #[Test]
    public function signatureWithoutKeyIdIsVerifiedAgainstLegacyKeysDuringRotation(): void
    {
        // Alte Signatur ohne Key-ID (2-teiliges Format) ...
        putenv('IMPEXPNL_SIGNING_KEY=alter-schluessel');
        $checksum = (new IntegrityService())->sign($this->sampleData());
        self::assertStringStartsWith('hmac-sha256:', $checksum);

        // ... bleibt nach der Rotation gültig, solange der Alt-Schlüssel hinterlegt ist.
        putenv('IMPEXPNL_SIGNING_KEY=neuer-schluessel');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = ['k2025' => 'alter-schluessel'];
        self::assertTrue((new IntegrityService())->verify($this->sampleData(), $checksum));

        // Ohne hinterlegten Alt-Schlüssel ist sie ungültig.
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys']);
        self::assertFalse((new IntegrityService())->verify($this->sampleData(), $checksum));
    }

    #[Test]
    public function plainChecksumIsRejectedWhenOnlyLegacyKeysAreConfigured(): void
    {
        $plain = $this->subject->sign($this->sampleData());
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = ['k2025' => 'alter-schluessel'];

        self::assertFalse((new IntegrityService())->verify($this->sampleData(), $plain));
    }

    #[Test]
    public function isHmacSignatureDetectsOnlyHmacScheme(): void
    {
        self::assertTrue($this->subject->isHmacSignature('hmac-sha256:abc'));
        self::assertFalse($this->subject->isHmacSignature('sha256:abc'));
        self::assertFalse($this->subject->isHmacSignature('abc'));
        self::assertFalse($this->subject->isHmacSignature(null));
    }

    #[Test]
    public function streamingHashEqualsArrayHash(): void
    {
        // Der streamende Hash (für JSONL) muss bei identischer Eingabe exakt denselben
        // Wert liefern wie der Array-Weg (computeHash via sign()).
        $data = $this->sampleData();

        foreach ([null, 'geheim'] as $key) {
            if ($key !== null) {
                putenv('IMPEXPNL_SIGNING_KEY=' . $key);
            } else {
                putenv('IMPEXPNL_SIGNING_KEY');
            }
            $subject = new IntegrityService();
            $expected = $subject->sign($data);

            // Streamende Tabellen in ksort-Reihenfolge, pro Aufruf FRISCH erzeugt
            // (Generatoren sind nur einmal iterierbar; die Rotations-Verifikation kann
            // die Closure mehrfach aufrufen – so wie der ManifestLoader es tut).
            $tableNames = array_keys($data);
            sort($tableNames);
            $makeTables = function () use ($tableNames, $data): \Generator {
                foreach ($tableNames as $name) {
                    yield [$name, $data[$name]];
                }
            };

            self::assertTrue(
                $subject->verifyStreaming($expected, fn(?string $k): string => $subject->computeHashStreaming($makeTables(), $k)),
                'Streaming-Hash muss die Array-Signatur verifizieren (key=' . var_export($key, true) . ')'
            );
        }
        putenv('IMPEXPNL_SIGNING_KEY');
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
