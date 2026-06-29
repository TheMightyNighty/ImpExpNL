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
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Beweis für Frage „wird das Bild im Importsystem angezeigt?": nach dem Import
 * liefert die Frontend-API FileRepository::findByRelation() das Bild für den neuen
 * Inhalt – genau das nutzt das FE (FilesProcessor/FileCollector) zum Rendern.
 */
class AssetDisplayTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    // frontend liefert die echte Inline-FAL-TCA für tt_content.image – ohne sie wäre das
    // Feld kein Inline-Relation-Feld und der Counter-Pflege-Pfad ließe sich nicht testen.
    protected array $coreExtensionsToLoad = [
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function importedImageIsFoundByFrontendRelationApi(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/bild.jpg', 'JPEGDATA');

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/bild.jpg')->getUid();

        // Bild-Referenz an einen CType hängen, der das image-Feld führt (CType "image").
        // Beim "text"-Typ ist image nicht im showitem; der DataHandler würde die Relation
        // beim regulären Import zurecht verwerfen.
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['CType' => 'image'],
            ['uid' => 10]
        );

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 2,
            'uid_local' => $sysFileUid,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 1,
        ]);

        $file = $this->instancePath . '/var/display.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->get(ExportService::class)->exportTree(1));
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);

        $newContent = (int)$this->resolveTargetUid('tt_content', 10);

        // Das ist der Weg, den das Frontend zum Rendern geht:
        $fileRepository = $this->get(FileRepository::class);
        $files = $fileRepository->findByRelation('tt_content', 'image', $newContent);

        self::assertCount(1, $files, 'Frontend-API findet das importierte Bild nicht');
        self::assertSame('bild.jpg', $files[0]->getName());

        // Das Zählfeld tt_content.image muss die Referenz mitzählen – sonst verwirft ein
        // späterer BE-Save die Relation (DataHandler rekonziliert Inline-Felder über den Count).
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $imageCount = $qb->select('image')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newContent, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame(1, (int)$imageCount, 'Zählfeld tt_content.image am importierten Inhalt ist stale (erwartet 1)');
    }
}
