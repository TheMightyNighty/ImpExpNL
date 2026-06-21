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

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\RollbackService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Contract des mitgelieferten Registry-Profils `sys_category_record_mm` (type: mm,
 * category_match: path): Kategorien werden über ihren Pfad gemappt, die MM-Relation
 * auf den neuen Inhalt umgeschrieben, ein Delta-Re-Import erzeugt keine Dubletten,
 * und der Rollback entfernt die importierte Relation wieder.
 */
class CategoryProfileContractTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/category.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    /** Anzahl MM-Relationen eines Content-Records. */
    private function relationCount(int $contentUid): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($contentUid, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
    }

    private function firstCategory(int $contentUid): ?int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        $v = $qb->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($contentUid, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        return $v === false ? null : (int)$v;
    }

    #[Test]
    public function exportContainsCategoryPaths(): void
    {
        $data = json_decode((string)file_get_contents($this->exportFile), true);
        self::assertArrayHasKey('sys_category_record_mm_with_paths', $data, 'Kategorie-MM mit Pfaden fehlt im Export');
    }

    #[Test]
    public function importRemapsCategoryToNewContent(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newContent = $this->resolveTargetUid('tt_content', 10);
        self::assertNotNull($newContent);
        self::assertSame(2, $this->firstCategory($newContent), 'Kategorie "Digitalisierung" (uid 2) nicht zugeordnet');
    }

    #[Test]
    public function deltaReimportDoesNotDuplicateRelation(): void
    {
        $import = $this->get(ImportService::class);
        $import->runImport($this->exportFile, 0, ['workspaceId' => 0]);
        $newContent = $this->resolveTargetUid('tt_content', 10);
        self::assertNotNull($newContent);
        self::assertSame(1, $this->relationCount($newContent), 'Nach Erst-Import unerwartete Relationsanzahl');

        $import->runImport($this->exportFile, 0, ['workspaceId' => 0, 'deltaMode' => true]);

        self::assertSame(1, $this->relationCount($newContent), 'Delta-Re-Import hat die Kategorie-Relation dupliziert');
    }

    #[Test]
    public function rollbackRemovesImportedRelation(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);
        $newContent = (int)$this->resolveTargetUid('tt_content', 10);
        self::assertSame(1, $this->relationCount($newContent));

        $this->get(RollbackService::class)->runRollback();

        self::assertSame(0, $this->relationCount($newContent), 'Rollback hat die importierte Kategorie-Relation nicht entfernt');
    }
}
