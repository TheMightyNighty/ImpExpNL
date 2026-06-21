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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Contract des mitgelieferten Registry-Profils `sys_redirect` (type: record):
 * Export der im Baum liegenden Redirects, Import mit UID-Remapping und
 * t3://-Link-Rewriting im `target`-Feld, sauberer Rollback.
 *
 * Hinweis: Registry-record-Tabellen sind heute bewusst noch nicht delta-idempotent
 * (Roadmap → „Später": Multi-Source-Delta für Registry-Tabellen). Diese Garantie
 * wird hier daher nicht behauptet.
 */
class RedirectProfileContractTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $coreExtensionsToLoad = [
        'redirects',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            self::markTestSkipped('Core-Extension "redirects" nicht geladen.');
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_redirect.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/redirect.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    private function redirect(int $uid): array|false
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
        $qb->getRestrictions()->removeAll();
        return $qb->select('source_path', 'target', 'pid')->from('sys_redirect')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
    }

    #[Test]
    public function exportContainsRedirectsOfTheTree(): void
    {
        $data = json_decode((string)file_get_contents($this->exportFile), true);
        self::assertArrayHasKey('sys_redirect', $data, 'sys_redirect fehlt im Export');
        $uids = array_column($data['sys_redirect'], 'uid');
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    #[Test]
    public function importRemapsRedirectsAndRewritesTarget(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newRedirect = $this->resolveTargetUid('sys_redirect', 1);
        $newPage = $this->resolveTargetUid('pages', 3);
        $newParentPage = $this->resolveTargetUid('pages', 2);
        self::assertNotNull($newRedirect, 'Redirect 1 wurde nicht gemappt');
        self::assertNotNull($newPage);
        self::assertNotNull($newParentPage);

        $row = $this->redirect($newRedirect);
        self::assertNotFalse($row);
        self::assertSame($newParentPage, (int)$row['pid'], 'pid des Redirects nicht auf neue Seite remappt');
        self::assertSame(
            't3://page?uid=' . $newPage,
            $row['target'],
            'target-Link wurde nicht auf die neue Seiten-UID umgeschrieben'
        );
    }

    #[Test]
    public function externalTargetIsPreserved(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newRedirect = $this->resolveTargetUid('sys_redirect', 2);
        self::assertNotNull($newRedirect);
        $row = $this->redirect($newRedirect);
        self::assertNotFalse($row);
        self::assertSame('https://example.org/extern', $row['target'], 'Externes Ziel darf nicht verändert werden');
    }

    #[Test]
    public function rollbackRemovesImportedRedirects(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);
        self::assertSame(2, $this->countMappedRecords('sys_redirect'));

        // Quelle = Ziel im Functional-Test: der Import legt neue Redirects neben den
        // Fixture-Originalen an. Der Rollback darf nur die importierten Kopien entfernen.
        $imported = [
            (int)$this->resolveTargetUid('sys_redirect', 1),
            (int)$this->resolveTargetUid('sys_redirect', 2),
        ];

        $this->get(RollbackService::class)->runRollback();

        self::assertSame(0, $this->countMappedRecords('sys_redirect'), 'Mapping nicht geleert');
        foreach ($imported as $uid) {
            $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
            $live = (int)$qb->count('uid')->from('sys_redirect')
                ->where(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->executeQuery()->fetchOne();
            self::assertSame(0, $live, "Importierter Redirect uid=$uid wurde nicht entfernt");
        }
    }
}
