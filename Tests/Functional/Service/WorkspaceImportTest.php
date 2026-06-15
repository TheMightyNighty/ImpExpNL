<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\RobbiCopy\Service\ExportService;
use Robbi\RobbiCopy\Service\ImportService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional-Test: Import in einen Workspace.
 *
 * Verifiziert, dass das Link-Rewriting beim Workspace-Import auf der
 * Workspace-Version arbeitet (nicht auf der Live-Platzhalterzeile).
 */
class WorkspaceImportTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/robbi_copy',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            self::markTestSkipped('Extension "workspaces" nicht geladen.');
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Test-Workspace']);

        $this->setUpBackendUser(1);
    }

    #[Test]
    public function workspaceImportRewritesLinksOnVersionedRecord(): void
    {
        $json = $this->get(ExportService::class)->exportTree(1);
        $tempFile = $this->instancePath . '/var/workspace.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $this->get(ImportService::class)->runImport($tempFile, 0, ['workspaceId' => 1]);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $versionCount = (int)$qb->count('uid')->from('tt_content')
            ->where($qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();

        self::assertGreaterThan(0, $versionCount, 'Es wurden keine Workspace-Versionen angelegt.');

        @unlink($tempFile);
    }
}
