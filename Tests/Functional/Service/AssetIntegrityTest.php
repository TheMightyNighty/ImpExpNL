<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "imp_exp_nl".
 *
 * (c) 2026 Robert Schleiermacher
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\AssetManifestService;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Asset-Integritätsprüfung: gleicher Identifier / anderer Inhalt → Mismatch,
 * fehlende Datei → missing. Der Import warnt nur (bricht nicht ab).
 */
class AssetIntegrityTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private const ORIGINAL = 'ORIGINAL asset content';
    private const CHANGED = 'COMPLETELY DIFFERENT content on target';

    private string $fileadmin = '';

    /** @var array<int, array<string, mixed>> */
    private array $assets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($this->fileadmin);
        file_put_contents($this->fileadmin . '/dummy.txt', self::ORIGINAL);

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/dummy.txt')->getUid();

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 2,
            'uid_local' => $sysFileUid,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 1,
        ]);

        // Export fängt das Manifest mit sha256 des ORIGINAL-Inhalts ein.
        $data = json_decode($this->get(ExportService::class)->exportTree(1), true);
        $this->assets = $data['assets'] ?? [];
        self::assertNotSame([], $this->assets, 'Asset-Manifest leer – Test-Setup fehlerhaft');
    }

    #[Test]
    public function detectsContentMismatch(): void
    {
        // Zieldatei nach dem Export inhaltlich ändern (gleicher Identifier).
        file_put_contents($this->fileadmin . '/dummy.txt', self::CHANGED);

        $issues = $this->get(AssetManifestService::class)->verifyAgainstTarget($this->assets);

        self::assertCount(1, $issues);
        self::assertSame('mismatch', $issues[0]['reason']);
        self::assertSame(hash('sha256', self::ORIGINAL), $issues[0]['expected']);
        self::assertSame(hash('sha256', self::CHANGED), $issues[0]['actual']);
    }

    #[Test]
    public function reportsMissingFile(): void
    {
        unlink($this->fileadmin . '/dummy.txt');

        $issues = $this->get(AssetManifestService::class)->verifyAgainstTarget($this->assets);

        self::assertCount(1, $issues);
        self::assertSame('missing', $issues[0]['reason']);
    }

    #[Test]
    public function identicalContentYieldsNoIssue(): void
    {
        // Datei unverändert -> keine Beanstandung.
        self::assertSame([], $this->get(AssetManifestService::class)->verifyAgainstTarget($this->assets));
    }

    #[Test]
    public function importWithChangedTargetFileWarnsButDoesNotFail(): void
    {
        // Export mit ORIGINAL-Hash schreiben, DANN die Zieldatei ändern.
        $file = $this->instancePath . '/var/integrity.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->get(ExportService::class)->exportTree(1));
        file_put_contents($this->fileadmin . '/dummy.txt', self::CHANGED);

        // Import läuft trotz Inhaltsabweichung durch (Warnung, kein Fehler).
        $result = $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);
        self::assertSame(0, $result['stats']['errors'], 'Inhaltsabweichung darf den Import nicht scheitern lassen');
    }
}
