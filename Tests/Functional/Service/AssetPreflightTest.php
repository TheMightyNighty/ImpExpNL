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
use Robbi\ImpExpNL\Service\ImportService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Asset-Preflight: der Import-Dry-Run meldet, welche im Manifest gelisteten Dateien
 * auf dem Ziel fehlen (müssen noch übertragen werden) bzw. abweichen.
 */
class AssetPreflightTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $fileadmin = '';
    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($this->fileadmin);
        file_put_contents($this->fileadmin . '/dummy.txt', 'Asset preflight fixture');

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

        $this->exportFile = $this->instancePath . '/var/preflight.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $this->get(ExportService::class)->exportTree(1));
    }

    /** @return array{total:int, missing:list<string>, mismatch:list<string>} */
    private function dryRunAssets(): array
    {
        $result = $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0, 'dryRun' => true]);
        self::assertArrayHasKey('assets', $result, 'Dry-Run liefert keinen Asset-Preflight');
        return $result['assets'];
    }

    #[Test]
    public function dryRunReportsAllAssetsPresent(): void
    {
        $assets = $this->dryRunAssets();
        self::assertSame(1, $assets['total']);
        self::assertSame([], $assets['missing']);
        self::assertSame([], $assets['mismatch']);
    }

    #[Test]
    public function dryRunReportsMissingAssetOnTarget(): void
    {
        unlink($this->fileadmin . '/dummy.txt');

        $assets = $this->dryRunAssets();
        self::assertSame(1, $assets['total']);
        self::assertContains('/dummy.txt', $assets['missing'], 'Fehlende Zieldatei nicht im Preflight gemeldet');
    }

    #[Test]
    public function dryRunReportsChangedAssetOnTarget(): void
    {
        file_put_contents($this->fileadmin . '/dummy.txt', 'different content after export');

        $assets = $this->dryRunAssets();
        self::assertContains('/dummy.txt', $assets['mismatch'], 'Inhaltsabweichung nicht im Preflight gemeldet');
        self::assertSame([], $assets['missing']);
    }
}
