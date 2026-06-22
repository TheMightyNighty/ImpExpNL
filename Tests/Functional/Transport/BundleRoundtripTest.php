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
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Bundle-Round-Trip (simuliert Cross-Instance): Export als .tar.gz, Asset-Datei auf
 * dem "Ziel" entfernen, Bundle importieren – die Datei muss aus dem Bundle ins
 * fileadmin zurückkehren und die FAL-Referenz auf dem neuen Inhalt entstehen.
 */
class BundleRoundtripTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private const FILE_CONTENT = 'ImpExpNL roundtrip asset';

    #[Test]
    public function bundleCarriesAssetToTargetAndReferenceResolves(): void
    {
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

        // Export als Bundle.
        $dir = $this->instancePath . '/var/roundtrip';
        GeneralUtility::mkdir_deep($dir);
        $result = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', ['transport' => 'bundle']);
        $bundle = $result['artifact'];
        self::assertNotNull($bundle);

        // "Ziel" hat die Datei (noch) nicht.
        unlink($fileadmin . '/dummy.txt');
        self::assertFileDoesNotExist($fileadmin . '/dummy.txt');

        // Bundle importieren -> Datei muss aus dem Bundle zurückkehren.
        $importResult = $this->get(ImportService::class)->runImport($bundle, 0, ['workspaceId' => 0]);
        self::assertSame(0, $importResult['stats']['errors']);

        self::assertFileExists($fileadmin . '/dummy.txt', 'Asset-Datei kam nicht aus dem Bundle ins fileadmin');
        self::assertSame(self::FILE_CONTENT, file_get_contents($fileadmin . '/dummy.txt'));

        // Die FAL-Referenz auf dem neu importierten Inhalt existiert.
        $newContent = (int)$this->resolveTargetUid('tt_content', 10);
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $count = (int)$qb->count('uid')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newContent, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $count, 'FAL-Referenz auf dem importierten Inhalt fehlt');
    }
}
