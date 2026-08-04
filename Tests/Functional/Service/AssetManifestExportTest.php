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
use Robbi\ImpExpNL\Service\ExportService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Gehärtetes Asset-Manifest: der Export sammelt pro eindeutiger Datei
 * storage/identifier/size/sha256 (im JSON unter `assets`) und schreibt zusätzlich
 * `impexpnl_assets.json`.
 */
class AssetManifestExportTest extends FunctionalTestCase
{
    private const FILE_CONTENT = 'ImpExpNL asset manifest fixture';
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/dummy.txt', self::FILE_CONTENT);

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/dummy.txt')->getUid();

        // Zwei Referenzen auf DIESELBE Datei (an Inhalt 10 und 11) -> Manifest dedupliziert.
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        foreach ([10, 11] as $i => $contentUid) {
            $connection->insert('sys_file_reference', [
                'pid' => 2,
                'uid_local' => $sysFileUid,
                'uid_foreign' => $contentUid,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sorting_foreign' => $i + 1,
            ]);
        }
    }

    #[Test]
    public function exportContainsHardenedAssetManifest(): void
    {
        $data = json_decode($this->get(ExportService::class)->exportTree(1), true);

        self::assertArrayHasKey('assets', $data, 'assets-Block fehlt im Export');
        self::assertCount(1, $data['assets'], 'Dieselbe Datei muss im Manifest dedupliziert sein');

        $asset = $data['assets'][0];
        self::assertSame('/dummy.txt', $asset['identifier']);
        self::assertSame(strlen(self::FILE_CONTENT), $asset['size'], 'Dateigröße im Manifest falsch');
        self::assertSame(hash('sha256', self::FILE_CONTENT), $asset['sha256'], 'sha256 im Manifest falsch');
        self::assertGreaterThan(0, $asset['storage']);
    }

    #[Test]
    public function runExportWritesAssetManifestFile(): void
    {
        $dir = $this->instancePath . '/var/assetexport';
        GeneralUtility::mkdir_deep($dir);
        $this->get(ExportService::class)->runExport(1, $dir . '/export.json');

        $manifestFile = $dir . '/impexpnl_assets.json';
        self::assertFileExists($manifestFile, 'impexpnl_assets.json wurde nicht geschrieben');

        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        self::assertSame(hash('sha256', self::FILE_CONTENT), $manifest[0]['sha256']);

        // rsync-Liste bleibt für Rückwärtskompatibilität erhalten.
        self::assertFileExists($dir . '/impexpnl_assets.txt');
    }
}
