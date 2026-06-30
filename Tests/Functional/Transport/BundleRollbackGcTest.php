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
use Robbi\ImpExpNL\Service\RollbackService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Asset-GC: Beim Bundle-Import platzierte Dateien werden beim Rollback wieder entfernt –
 * aber referenz-geprüft (nur wenn nach dem Rollback keine sys_file_reference mehr darauf
 * zeigt). Verhindert „Karteileichen" im fileadmin des Zielsystems.
 */
class BundleRollbackGcTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = ['typo3conf/ext/imp_exp_nl'];

    private const FILE_CONTENT = 'ImpExpNL rollback-gc asset';

    #[Test]
    public function rollbackRemovesAssetsPlacedByBundleImport(): void
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
            'pid' => 2, 'uid_local' => $sysFileUid, 'uid_foreign' => 10,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'sorting_foreign' => 1,
        ]);

        // Export als Bundle.
        $dir = $this->instancePath . '/var/gc';
        GeneralUtility::mkdir_deep($dir);
        $bundle = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', ['transport' => 'bundle'])['artifact'];

        // „Ziel" sauber machen: Ursprungs-Referenz entfernen, damit nach dem Import nur
        // die importierte Referenz auf die Datei zeigt (Einzel-Instanz teilt sich die DB).
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->delete('sys_file_reference', ['uid_foreign' => 10, 'tablenames' => 'tt_content']);
        // Ziel hat die Datei (noch) nicht → der Import muss sie selbst platzieren.
        unlink($fileadmin . '/dummy.txt');

        $result = $this->get(ImportService::class)->runImport($bundle, 0, ['workspaceId' => 0]);
        $importId = $result['importId'];

        // Datei wurde platziert + eine neue sys_file-UID entstanden.
        self::assertFileExists($fileadmin . '/dummy.txt', 'Bundle-Import hat die Datei nicht platziert');
        $placedUid = $this->placedSysFileUid('/dummy.txt');
        self::assertGreaterThan(0, $placedUid);
        self::assertSame(1, $this->countLiveReferences($placedUid), 'Referenz auf platziertes Asset fehlt');

        // Rollback.
        $this->get(RollbackService::class)->runRollback($importId, true);

        // GC: Referenz weg, sys_file-Record weg, physische Datei weg.
        self::assertSame(0, $this->countLiveReferences($placedUid), 'Referenz nach Rollback nicht entfernt');
        self::assertSame(0, $this->countSysFile($placedUid), 'platziertes sys_file nach Rollback nicht entfernt');
        self::assertFileDoesNotExist($fileadmin . '/dummy.txt', 'platzierte Datei nach Rollback nicht entfernt');
    }

    private function placedSysFileUid(string $identifier): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->select('uid')->from('sys_file')
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
            ->executeQuery()->fetchOne();
    }

    private function countSysFile(int $uid): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('sys_file')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    private function countLiveReferences(int $uidLocal): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($uidLocal, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
    }
}
