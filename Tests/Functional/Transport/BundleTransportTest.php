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

namespace Robbi\ImpExpNL\Tests\Functional\Transport;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Bundle-Transport: der Export wird in ein self-contained `.tar.gz` gepackt
 * (Export-Datei + Asset-Manifest + die referenzierten Dateien selbst).
 */
class BundleTransportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private const FILE_CONTENT = 'ImpExpNL bundle fixture content';

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

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 2,
            'uid_local' => $sysFileUid,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 1,
        ]);
    }

    #[Test]
    public function externalTransportProducesNoArtifact(): void
    {
        $dir = $this->instancePath . '/var/ext';
        GeneralUtility::mkdir_deep($dir);
        $result = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', ['transport' => 'external']);

        self::assertSame('external', $result['type']);
        self::assertNull($result['artifact']);
    }

    #[Test]
    public function bundleTransportCreatesSelfContainedArchive(): void
    {
        $dir = $this->instancePath . '/var/bundle';
        GeneralUtility::mkdir_deep($dir);
        $result = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', ['transport' => 'bundle']);

        self::assertSame('bundle', $result['type']);
        self::assertNotNull($result['artifact']);
        self::assertFileExists($result['artifact']);
        self::assertStringEndsWith('.tar.gz', $result['artifact']);

        // PharData cacht Archive prozessweit pro Pfad; das soeben erzeugte Bundle wird
        // im selben Prozess über eine eindeutige Kopie gelesen (cache-frei, wie ein
        // echter Import in getrenntem Prozess).
        $readCopy = $dir . '/verify_' . bin2hex(random_bytes(4)) . '.tar.gz';
        copy($result['artifact'], $readCopy);
        $phar = new \PharData($readCopy);
        self::assertTrue(isset($phar['export.json']), 'Export-Datei fehlt im Bundle');
        self::assertTrue(isset($phar['impexpnl_assets.json']), 'Asset-Manifest fehlt im Bundle');
        self::assertTrue(isset($phar['files/dummy.txt']), 'Asset-Datei fehlt im Bundle');
        self::assertSame(self::FILE_CONTENT, $phar['files/dummy.txt']->getContent(), 'Asset-Inhalt im Bundle falsch');

        // Die unkomprimierte .tar darf nicht zurückbleiben.
        self::assertFileDoesNotExist($dir . '/export.tar');
    }

    #[Test]
    public function bundleWithoutAssetsOmitsFiles(): void
    {
        $dir = $this->instancePath . '/var/bundle_noassets';
        GeneralUtility::mkdir_deep($dir);
        $result = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', [
            'transport' => 'bundle',
            'transportConfig' => ['includeAssets' => false],
        ]);

        $phar = new \PharData($result['artifact']);
        self::assertTrue(isset($phar['export.json']));
        self::assertFalse(isset($phar['files/dummy.txt']), 'Asset-Datei darf ohne includeAssets nicht enthalten sein');
    }
}
