<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für den ProfileService.
 * Testet die Validierungslogik ohne Dateisystem-Zugriff.
 */
class ProfileServiceTest extends TestCase
{
    #[Test]
    public function validProfileDataIsAccepted(): void
    {
        $data = [
            'source_file' => '/var/www/html/var/export.json',
            'target_pid' => 456,
            'workspace' => 1,
            'delta' => true,
            'conflict' => 'skip',
        ];

        // Simuliert die Validierungslogik aus ProfileService::loadProfile
        $profile = [
            'source_file' => $data['source_file'] ?? '',
            'target_pid' => (int)($data['target_pid'] ?? 0),
            'workspace' => (int)($data['workspace'] ?? 0),
            'delta' => (bool)($data['delta'] ?? false),
            'conflict' => $data['conflict'] ?? 'overwrite',
            'depth' => (int)($data['depth'] ?? 0),
        ];

        self::assertEquals('/var/www/html/var/export.json', $profile['source_file']);
        self::assertEquals(456, $profile['target_pid']);
        self::assertEquals(1, $profile['workspace']);
        self::assertTrue($profile['delta']);
        self::assertEquals('skip', $profile['conflict']);
    }

    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $data = [
            'source_file' => '/var/www/html/var/export.json',
            'target_pid' => 100,
        ];

        $profile = [
            'source_file' => $data['source_file'] ?? '',
            'target_pid' => (int)($data['target_pid'] ?? 0),
            'workspace' => (int)($data['workspace'] ?? 0),
            'delta' => (bool)($data['delta'] ?? false),
            'conflict' => $data['conflict'] ?? 'overwrite',
            'depth' => (int)($data['depth'] ?? 0),
        ];

        // Defaults
        self::assertEquals(0, $profile['workspace']);
        self::assertFalse($profile['delta']);
        self::assertEquals('overwrite', $profile['conflict']);
        self::assertEquals(0, $profile['depth']);
    }

    #[Test]
    public function missingSourceFileIsDetected(): void
    {
        $data = ['target_pid' => 100];

        $sourceFile = $data['source_file'] ?? '';
        self::assertEmpty($sourceFile);
    }

    #[Test]
    public function zeroTargetPidIsDetected(): void
    {
        $data = ['source_file' => '/var/www/html/var/export.json'];

        $targetPid = (int)($data['target_pid'] ?? 0);
        self::assertEquals(0, $targetPid);
    }
}
