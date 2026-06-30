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

namespace Robbi\ImpExpNL\Tests\Functional\Profile;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Contract des FAL-Profils: Seiten + Inhalte mit einer echten sys_file_reference
 * (tt_content.image). Das Profil gilt als unterstützt, solange Export alle Records
 * enthält, der Import die FAL-Referenz auf dem neuen Inhalt herstellt (inkl. korrekt
 * gepflegtem Zählfeld, sodass die Frontend-Render-API das Bild findet), ein
 * Delta-Re-Import idempotent ist und der Rollback alles entfernt.
 */
class FalProfileContractTest extends AbstractProfileContract
{
    // frontend liefert die echte Inline-FAL-TCA für tt_content.image.
    protected array $coreExtensionsToLoad = ['frontend'];

    protected function profileFixtures(): array
    {
        return [
            __DIR__ . '/../Fixtures/pages.csv',
            __DIR__ . '/../Fixtures/tt_content.csv',
        ];
    }

    protected function expectedSourceUids(): array
    {
        return [
            'pages' => [1, 2, 3, 4],
            'tt_content' => [10, 11, 12, 13],
        ];
    }

    /**
     * FAL-Zustand VOR dem Export: echten fileadmin-Storage + Datei anlegen, Inhalt 10
     * auf CType "image" setzen und eine sys_file_reference darauf einhängen.
     */
    protected function prepareProfileState(): void
    {
        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);
        file_put_contents($fileadmin . '/bild.jpg', 'JPEGDATA');

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        $sysFileUid = $storageRepository->findByUid($storageUid)->getFile('/bild.jpg')->getUid();

        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['CType' => 'image'], ['uid' => 10]);
        $connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 2,
            'uid_local' => $sysFileUid,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sorting_foreign' => 1,
        ]);
    }

    protected function verifyFalReferences(): void
    {
        $newContent = (int)$this->resolveTargetUid('tt_content', 10);
        self::assertGreaterThan(0, $newContent);

        // Frontend-Render-Weg: FileRepository findet das Bild für den neuen Inhalt.
        $files = $this->get(FileRepository::class)->findByRelation('tt_content', 'image', $newContent);
        self::assertCount(1, $files, 'FAL: Frontend-API findet das importierte Bild nicht');
        self::assertSame('bild.jpg', $files[0]->getName());

        // Denormalisiertes Zählfeld muss die Referenz mitzählen.
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $imageCount = $qb->select('image')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newContent, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame(1, (int)$imageCount, 'FAL: Zählfeld tt_content.image am importierten Inhalt ist stale');
    }
}
