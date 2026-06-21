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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * FAL-Edge-Cases: Referenz-Metadaten (crop/alt/title/description) bleiben erhalten,
 * und fehlende Dateien auf dem Zielsystem werden sauber übersprungen.
 */
class FalEdgeCasesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/dummy.txt', 'ImpExpNL FAL fixture');

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/dummy.txt')->getUid();

        // Referenz mit Metadaten auf tt_content uid=10 ("Willkommen").
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->insert(
            'sys_file_reference',
            [
                'pid' => 2,
                'uid_local' => $sysFileUid,
                'uid_foreign' => 10,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sorting_foreign' => 1,
                'title' => 'Mein Titel',
                'alternative' => 'Alt-Text',
                'description' => 'Beschreibung',
                'crop' => '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1}}}',
            ]
        );

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/fal_edge.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    private function importedUid(string $table, int $remoteUid): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')->from($table)
            ->where($qb->expr()->eq('tx_impexpnl_remote_uid', $qb->createNamedParameter($remoteUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        return $uid !== false ? (int)$uid : null;
    }

    private function importedReference(): array|false
    {
        $newContentUid = $this->importedUid('tt_content', 10);
        self::assertNotNull($newContentUid, 'Mapping für importierten Content fehlt');

        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        return $qb->select('title', 'alternative', 'description', 'crop')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newContentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchAssociative();
    }

    #[Test]
    public function referenceMetadataIsPreserved(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $row = $this->importedReference();
        self::assertNotFalse($row, 'Importierte FAL-Referenz fehlt');
        self::assertSame('Mein Titel', $row['title']);
        self::assertSame('Alt-Text', $row['alternative']);
        self::assertSame('Beschreibung', $row['description']);
        self::assertStringContainsString('cropArea', (string)$row['crop']);
    }

    #[Test]
    public function missingTargetFileIsSkippedWithoutError(): void
    {
        // Datei auf dem „Zielsystem" entfernen -> Referenz darf nicht angelegt werden.
        unlink(Environment::getPublicPath() . '/fileadmin/dummy.txt');

        $result = $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        self::assertSame(0, $result['stats']['errors'], 'Fehlende FAL-Datei darf keinen DataHandler-Fehler verursachen');
        self::assertFalse($this->importedReference(), 'Für eine fehlende Datei darf keine Referenz angelegt werden');
    }
}
