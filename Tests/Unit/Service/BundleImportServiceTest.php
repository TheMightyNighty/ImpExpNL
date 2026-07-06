<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Robbi\ImpExpNL\Exception\IntegrityException;
use Robbi\ImpExpNL\Service\BundleImportService;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Unit-Tests für die Sicherheits-Invarianten der Bundle-Asset-Platzierung:
 * Path-Traversal-Schutz und Verifikation gegen die signierte sha256-Prüfsumme.
 * Alle Prüfungen greifen VOR dem Storage-Zugriff, daher genügt ein Stub.
 */
class BundleImportServiceTest extends TestCase
{
    private BundleImportService $subject;
    private string $bundleDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new BundleImportService(
            self::createStub(StorageRepository::class),
            new NullLogger()
        );
        $this->bundleDir = sys_get_temp_dir() . '/impexpnl_bundle_test_' . bin2hex(random_bytes(4));
        mkdir($this->bundleDir . '/files/user_upload', 0775, true);
        file_put_contents($this->bundleDir . '/files/user_upload/bild.jpg', 'BILD-INHALT');
        // Datei außerhalb von files/ – darf über Traversal nicht erreichbar sein.
        file_put_contents($this->bundleDir . '/geheim.txt', 'SECRET');
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->bundleDir . '/files/user_upload/bild.jpg',
            $this->bundleDir . '/geheim.txt',
        ] as $file) {
            @unlink($file);
        }
        @rmdir($this->bundleDir . '/files/user_upload');
        @rmdir($this->bundleDir . '/files');
        @rmdir($this->bundleDir);
        parent::tearDown();
    }

    #[Test]
    public function traversalIdentifierIsSkipped(): void
    {
        $this->subject->placeAssets($this->bundleDir, [
            ['storage' => 1, 'identifier' => '../geheim.txt', 'sha256' => hash('sha256', 'SECRET')],
            ['storage' => 1, 'identifier' => '/user_upload/../../geheim.txt', 'sha256' => hash('sha256', 'SECRET')],
            ['storage' => 1, 'identifier' => "/user_upload/\0evil", 'sha256' => 'x'],
            ['storage' => 1, 'identifier' => '\\evil\\pfad', 'sha256' => 'x'],
        ]);

        self::assertSame([], $this->subject->getPlacedFileUids());
    }

    #[Test]
    public function assetWithoutSignedChecksumIsSkipped(): void
    {
        $this->subject->placeAssets($this->bundleDir, [
            ['storage' => 1, 'identifier' => '/user_upload/bild.jpg', 'sha256' => null],
            ['storage' => 1, 'identifier' => '/user_upload/bild.jpg'],
        ]);

        self::assertSame([], $this->subject->getPlacedFileUids());
    }

    #[Test]
    public function tamperedAssetContentThrowsIntegrityException(): void
    {
        $this->expectException(IntegrityException::class);

        $this->subject->placeAssets($this->bundleDir, [
            ['storage' => 1, 'identifier' => '/user_upload/bild.jpg', 'sha256' => hash('sha256', 'ANDERER-INHALT')],
        ]);
    }

    #[Test]
    public function bundleWithSymlinkMemberIsRejectedBeforeExtraction(): void
    {
        $bundle = $this->createBundleWithSymlinkMember();

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageMatches('/link member/');
        try {
            $this->subject->extract($bundle);
        } finally {
            @unlink($bundle);
        }
    }

    /**
     * Baut per nativem tar ein Archiv, das erst einen Symlink und danach eine
     * Datei enthält – das Muster, mit dem eine Extraktion durch den Symlink
     * außerhalb des Zielverzeichnisses schreiben könnte.
     */
    private function createBundleWithSymlinkMember(): string
    {
        $work = sys_get_temp_dir() . '/impexpnl_evil_' . bin2hex(random_bytes(4));
        mkdir($work, 0775, true);
        if (!@symlink('/tmp', $work . '/link')) {
            self::markTestSkipped('symlink() auf diesem System nicht verfügbar.');
        }
        file_put_contents($work . '/export.json', '{}');
        $bundle = $work . '.tar.gz';
        exec(sprintf(
            'tar -czf %s -C %s link export.json 2>/dev/null',
            escapeshellarg($bundle),
            escapeshellarg($work)
        ), $ignored, $exitCode);
        @unlink($work . '/link');
        @unlink($work . '/export.json');
        @rmdir($work);
        if ($exitCode !== 0 || !is_file($bundle)) {
            self::markTestSkipped('Natives tar auf diesem System nicht verfügbar.');
        }
        return $bundle;
    }
}
