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
 * Functional-Test für den FAL-Referenz-Pfad (Export → Import → Auflösung).
 *
 * Deckt {@see \Robbi\ImpExpNL\Service\FalResolverService::importReferences()} ab:
 * eine tt_content-Datei mit sys_file_reference wird exportiert, unter neuer PID
 * importiert und die Referenz muss über Storage + Datei-Identifier auf die
 * Zieldatei neu aufgelöst werden. Sichert u. a. die v14-Umstellung von
 * ResourceFactory::getStorageObject() auf StorageRepository::getStorageObject() ab.
 */
class FalReferenceImportTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private int $storageUid = 0;
    private int $sysFileUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        // Lokalen Storage + echte Datei im Test-fileadmin anlegen.
        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/dummy.txt', 'ImpExpNL FAL fixture');

        $storageRepository = $this->get(StorageRepository::class);
        $this->storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        // getFile() indexiert die Datei → erzeugt den sys_file-Datensatz.
        $storage = $storageRepository->findByUid($this->storageUid);
        self::assertNotNull($storage, 'Storage konnte nicht erstellt werden');
        $this->sysFileUid = $storage->getFile('/dummy.txt')->getUid();

        // Quell-Referenz: tt_content uid=10 ("Willkommen") -> Datei.
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert(
            'sys_file_reference',
            [
                'pid' => 2,
                'uid_local' => $this->sysFileUid,
                'uid_foreign' => 10,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sorting_foreign' => 1,
            ]
        );
    }

    #[Test]
    public function importResolvesFalReferenceToTargetFile(): void
    {
        // Export muss die Referenz inkl. Datei-Identifier + Storage enthalten.
        $json = $this->get(ExportService::class)->exportTree(1);
        $data = json_decode($json, true);

        self::assertNotEmpty($data['sys_file_reference'] ?? [], 'Export enthält keine FAL-Referenz');
        $exportedRef = $data['sys_file_reference'][0];
        self::assertSame('/dummy.txt', $exportedRef['identifier'], 'Datei-Identifier fehlt im Export');
        self::assertSame($this->storageUid, (int)$exportedRef['storage'], 'Storage-ID fehlt im Export');

        // Import unter neuer Wurzel.
        $importFile = $this->instancePath . '/var/fal_import.json';
        @mkdir(dirname($importFile), 0775, true);
        file_put_contents($importFile, $json);
        $this->get(ImportService::class)->runImport($importFile, 0, ['workspaceId' => 0]);

        // Neu importierten Content ("Willkommen", source-uid=10) über das Mapping finden.
        $newContentUid = $this->resolveTargetUid('tt_content', 10);
        self::assertNotNull($newContentUid, 'Mapping für importierten Content nicht gefunden');
        self::assertNotSame(10, $newContentUid, 'Importierter Content muss eine neue UID haben');

        // Es muss eine FAL-Referenz auf den neuen Content geben, die auf eine
        // sys_file-Datei mit identischem Identifier zeigt (= neu aufgelöst).
        $refQb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $refQb->getRestrictions()->removeAll();
        $resolvedFileUid = (int)$refQb->select('uid_local')->from('sys_file_reference')
            ->where(
                $refQb->expr()->eq('tablenames', $refQb->createNamedParameter('tt_content')),
                $refQb->expr()->eq('uid_foreign', $refQb->createNamedParameter($newContentUid, Connection::PARAM_INT)),
                $refQb->expr()->eq('deleted', $refQb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $resolvedFileUid, 'Importierte FAL-Referenz fehlt');

        $fileQb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $fileQb->getRestrictions()->removeAll();
        $identifier = $fileQb->select('identifier')->from('sys_file')
            ->where($fileQb->expr()->eq('uid', $fileQb->createNamedParameter($resolvedFileUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame('/dummy.txt', $identifier, 'FAL-Referenz wurde nicht auf die Zieldatei aufgelöst');
    }
}
