<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\RollbackService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Stellt sicher, dass ein Rollback eines Delta-Imports keine vorbestehenden
 * (nur gematchten) Records löscht.
 */
class DeltaRollbackTest extends FunctionalTestCase
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

    #[Test]
    public function rollbackOfDeltaImportKeepsMatchedRecords(): void
    {
        $json = $this->get(ExportService::class)->exportTree(1);
        $tempFile = $this->instancePath . '/var/delta_rollback.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $importService = $this->get(ImportService::class);

        // Erstimport legt den Baum an.
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);
        $pagesAfterFirst = $this->countImported('pages');
        self::assertGreaterThan(0, $pagesAfterFirst, 'Erstimport hat keine Seiten angelegt');

        // Delta-Import derselben Datei: alles identisch -> nichts neu angelegt.
        $importService->runImport($tempFile, 0, ['workspaceId' => 0, 'deltaMode' => true]);

        // Rollback des (leeren) Delta-Imports darf die zuvor angelegten Records NICHT löschen.
        $this->get(RollbackService::class)->runRollback();

        self::assertSame(
            $pagesAfterFirst,
            $this->countImported('pages'),
            'Rollback des Delta-Imports hat vorbestehende Records gelöscht'
        );
    }

    private function countImported(string $table): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        return (int)$qb->count('uid')->from('tx_impexpnl_uid_map')
            ->where($qb->expr()->eq('table_name', $qb->createNamedParameter($table)))
            ->executeQuery()->fetchOne();
    }
}
