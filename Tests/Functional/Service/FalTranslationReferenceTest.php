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
 * FAL-Referenz auf einem übersetzten Inhalt (sys_language_uid > 0) wird mit-exportiert
 * und auf dem Ziel an der neuen Übersetzung wieder angelegt.
 */
class FalTranslationReferenceTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_l10n.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_l10n.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/dummy.txt', 'ImpExpNL FAL translation fixture');

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/dummy.txt')->getUid();

        // Referenz auf den ÜBERSETZTEN Inhalt (tt_content uid=11, sys_language_uid=1).
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert(
            'sys_file_reference',
            [
                'pid' => 2,
                'uid_local' => $sysFileUid,
                'uid_foreign' => 11,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sys_language_uid' => 1,
                'sorting_foreign' => 1,
                'title' => 'Bild der Übersetzung',
            ]
        );

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/fal_translation.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    #[Test]
    public function referenceOnTranslatedContentIsImported(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newTranslation = $this->resolveTargetUid('tt_content', 11);
        self::assertNotNull($newTranslation, 'Übersetzter Inhalt wurde nicht importiert');

        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('title')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newTranslation, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchAssociative();

        self::assertNotFalse($row, 'FAL-Referenz an der Übersetzung fehlt');
        self::assertSame('Bild der Übersetzung', $row['title'], 'Metadaten der Übersetzungs-Referenz nicht erhalten');
    }
}
