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
use Robbi\ImpExpNL\Service\RollbackService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Rollback-Sicherheit: nach dem Import lokal geänderte Ziel-Records dürfen nicht
 * blind gelöscht werden – Abbruch ohne --force, Durchführung mit --force.
 */
class RollbackSafetyTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Importiert den Baum und markiert eine importierte Seite als lokal geändert
     * (tstamp in der Zukunft). Liefert die UID der geänderten Zielseite.
     */
    private function importAndModify(): int
    {
        $json = $this->get(ExportService::class)->exportTree(1);
        $file = $this->instancePath . '/var/rollback_safety.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $json);
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);

        $mapQb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $targetUid = (int)$mapQb->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where(
                $mapQb->expr()->eq('table_name', $mapQb->createNamedParameter('pages')),
                $mapQb->expr()->eq('source_uid', $mapQb->createNamedParameter(2, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')
            ->update('pages', ['title' => 'LOKAL GEÄNDERT', 'tstamp' => time() + 100000], ['uid' => $targetUid]);

        return $targetUid;
    }

    private function exists(int $uid): bool
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne() > 0;
    }

    #[Test]
    public function rollbackAbortsOnLocallyModifiedRecordWithoutForce(): void
    {
        $targetUid = $this->importAndModify();

        try {
            $this->get(RollbackService::class)->runRollback();
            self::fail('Rollback hätte wegen lokaler Änderung abbrechen müssen.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('lokal', $e->getMessage());
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertTrue($this->exists($targetUid), 'Rollback hat trotz Abbruch gelöscht');
    }

    #[Test]
    public function rollbackWithForceRemovesLocallyModifiedRecord(): void
    {
        $targetUid = $this->importAndModify();

        $this->get(RollbackService::class)->runRollback(null, true);

        self::assertFalse($this->exists($targetUid), 'Rollback mit --force hat den Record nicht entfernt');
    }

    #[Test]
    public function rollbackToleratesAlreadyDeletedTargetRecord(): void
    {
        $json = $this->get(ExportService::class)->exportTree(1);
        $file = $this->instancePath . '/var/rollback_predeleted.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $json);
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);

        // Einen importierten Inhalt vorab löschen (z.B. Redakteur hat ihn entfernt).
        $cQb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $contentUid = (int)$cQb->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where(
                $cQb->expr()->eq('table_name', $cQb->createNamedParameter('tt_content')),
                $cQb->expr()->eq('source_uid', $cQb->createNamedParameter(10, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $contentUid);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => $contentUid]);

        // Rollback muss den bereits gelöschten Record tolerieren und sauber durchlaufen.
        $this->get(RollbackService::class)->runRollback();

        self::assertSame(0, $this->countMapped('pages'), 'Seiten-Mapping nicht geleert');
        self::assertSame(0, $this->countMapped('tt_content'), 'Inhalts-Mapping nicht geleert');
    }

    private function countMapped(string $table): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        return (int)$qb->count('uid')->from('tx_impexpnl_uid_map')
            ->where($qb->expr()->eq('table_name', $qb->createNamedParameter($table)))
            ->executeQuery()->fetchOne();
    }
}
