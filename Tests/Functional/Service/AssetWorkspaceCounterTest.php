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
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Import in einen Nicht-Live-Workspace: die neuen Inhalte entstehen als
 * Workspace-Neu-Platzhalter (t3ver_wsid>0, t3ver_state=1) mit eigener UID. Der
 * denormalisierte FAL-Zähler muss auch dort stimmen – der DataHandler pflegt ihn
 * nicht, das tut der Nachpass in {@see \Robbi\ImpExpNL\Service\FalResolverService}.
 */
class AssetWorkspaceCounterTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $coreExtensionsToLoad = ['frontend', 'workspaces'];

    protected array $testExtensionsToLoad = ['typo3conf/ext/imp_exp_nl'];

    #[Test]
    public function falCounterIsCorrectAfterImportIntoWorkspace(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 1, 'pid' => 0, 'title' => 'WS1',
        ]);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/bild.jpg', 'JPEGDATA');
        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/bild.jpg')->getUid();

        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['CType' => 'image'], ['uid' => 10]);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 2, 'uid_local' => $sysFileUid, 'uid_foreign' => 10,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'sorting_foreign' => 1,
        ]);

        $file = $this->instancePath . '/var/ws.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->get(ExportService::class)->exportTree(1));
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 1]);

        $newContent = (int)$this->resolveTargetUid('tt_content', 10);

        // Der Ziel-Record ist ein Workspace-Neu-Platzhalter; sein Zählfeld muss 1 sein.
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('image', 't3ver_wsid')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newContent, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();

        self::assertSame(1, (int)$row['t3ver_wsid'], 'Erwartet wurde ein Workspace-Record');
        self::assertSame(1, (int)$row['image'], 'FAL-Zählfeld am Workspace-Record ist stale (erwartet 1)');
    }
}
