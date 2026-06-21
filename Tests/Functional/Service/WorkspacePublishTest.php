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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Workspace-Lebenszyklus: Import in einen Workspace legt Versionen an, ein
 * Delta-Re-Import bleibt idempotent, die Freigabe (Publish) überführt die
 * Versionen sauber nach Live (inkl. umgeschriebener Links), und ein Rollback
 * entfernt die Workspace-Versionen wieder.
 */
class WorkspacePublishTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private const WS = 1;

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            self::markTestSkipped('Extension "workspaces" nicht geladen.');
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => self::WS, 'pid' => 0, 'title' => 'Test-Workspace']);

        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/ws_publish.json';
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

    private function wsid(string $table, int $uid): ?int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('t3ver_wsid')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        return $row === false ? null : (int)$row;
    }

    private function countVersions(string $table): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from($table)
            ->where($qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(self::WS, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    private function bodytext(int $uid): string
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        return (string)$qb->select('bodytext')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    #[Test]
    public function importCreatesWorkspaceVersions(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => self::WS]);

        self::assertGreaterThan(0, $this->countVersions('pages'), 'Keine Seiten-Versionen im Workspace');
        self::assertGreaterThan(0, $this->countVersions('tt_content'), 'Keine Inhalts-Versionen im Workspace');

        $newContent = $this->importedUid('tt_content', 10);
        self::assertNotNull($newContent);
        self::assertSame(self::WS, $this->wsid('tt_content', $newContent), 'Importierter Inhalt liegt nicht im Workspace');
    }

    #[Test]
    public function deltaReimportIntoWorkspaceIsIdempotent(): void
    {
        $importService = $this->get(ImportService::class);
        $importService->runImport($this->exportFile, 0, ['workspaceId' => self::WS]);
        $before = $this->countVersions('tt_content');

        $result = $importService->runImport($this->exportFile, 0, ['workspaceId' => self::WS, 'deltaMode' => true]);

        self::assertSame(0, (int)($result['stats']['new'] ?? -1), 'WS-Delta legt neue Records an');
        self::assertSame($before, $this->countVersions('tt_content'), 'WS-Delta erzeugt doppelte Versionen');
    }

    #[Test]
    public function publishMakesImportedRecordsLiveWithRewrittenLinks(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => self::WS]);

        $newContent = $this->importedUid('tt_content', 10);
        $newPage = $this->importedUid('pages', 3);
        self::assertNotNull($newContent);
        self::assertNotNull($newPage);
        self::assertSame(self::WS, $this->wsid('tt_content', $newContent), 'Vor Publish muss der Record im Workspace liegen');

        (new ActionService())->publishWorkspace(self::WS);

        self::assertSame(0, $this->wsid('tt_content', $newContent), 'Nach Publish muss der Record live (wsid=0) sein');
        self::assertStringContainsString(
            't3://page?uid=' . $newPage,
            $this->bodytext($newContent),
            'Link im freigegebenen Live-Record nicht auf neue Seiten-UID umgeschrieben'
        );
    }

    #[Test]
    public function rollbackRemovesWorkspaceVersions(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => self::WS]);
        self::assertGreaterThan(0, $this->countVersions('tt_content'));

        $this->get(RollbackService::class)->runRollback();

        self::assertSame(0, $this->countVersions('tt_content'), 'Rollback: Workspace-Versionen blieben bestehen');
        self::assertSame(0, $this->countVersions('pages'), 'Rollback: Seiten-Versionen blieben bestehen');
    }
}
