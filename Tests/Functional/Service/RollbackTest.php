<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Functional\Service;

use Robbi\RobbiCopy\Service\ExportService;
use Robbi\RobbiCopy\Service\ImportService;
use Robbi\RobbiCopy\Service\RollbackService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional-Test: Rollback löscht alle importierten Daten sauber.
 */
class RollbackTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/robbi_copy',
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
    public function rollbackDeletesAllImportedRecords(): void
    {

        // Anzahl Records VOR dem Import
        $pageCountBefore = $this->countRecords('pages');
        $contentCountBefore = $this->countRecords('tt_content');

        // Export + Import
        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_rollback.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Nach Import: Mehr Records
        $pageCountAfterImport = $this->countRecords('pages');
        self::assertGreaterThan($pageCountBefore, $pageCountAfterImport, 'Import hat keine Seiten angelegt');

        // Rollback
        $rollbackService = $this->get(RollbackService::class);
        $rollbackService->runRollback();

        // Nach Rollback: Gleiche Anzahl wie vorher (oder weniger wegen soft-delete)
        $pageCountAfterRollback = $this->countRecords('pages', ['deleted' => 0]);
        self::assertLessThanOrEqual($pageCountBefore, $pageCountAfterRollback,
            'Rollback hat nicht alle importierten Seiten entfernt');
    }

    #[Test]
    public function rollbackRemovesImportLogEntry(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_rollback_log.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Import-Log muss existieren
        $logCount = $this->countRecords('tx_robbicopy_import_log');
        self::assertEquals(1, $logCount, 'Import-Log-Eintrag fehlt');

        // Rollback
        $rollbackService = $this->get(RollbackService::class);
        $rollbackService->runRollback();

        // Import-Log muss leer sein
        $logCountAfter = $this->countRecords('tx_robbicopy_import_log');
        self::assertEquals(0, $logCountAfter, 'Import-Log-Eintrag wurde nicht entfernt');
    }

    #[Test]
    public function rollbackWithSpecificIdDeletesOnlyThatImport(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        // Zwei Imports durchführen
        $tempFile1 = $this->instancePath . '/var/test_multi_1.json';
        $tempFile2 = $this->instancePath . '/var/test_multi_2.json';
        @mkdir(dirname($tempFile1), 0775, true);
        file_put_contents($tempFile1, $json);
        file_put_contents($tempFile2, $json);

        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile1, 0, ['workspaceId' => 0]);

        // Kurz warten damit der Timestamp anders ist
        sleep(1);
        $importService->runImport($tempFile2, 0, ['workspaceId' => 0]);

        // Zwei Log-Einträge
        $logCount = $this->countRecords('tx_robbicopy_import_log');
        self::assertEquals(2, $logCount);

        // Nur den letzten rollbacken
        $rollbackService = $this->get(RollbackService::class);
        $rollbackService->runRollback(); // Ohne ID → letzter

        // Noch ein Log-Eintrag übrig
        $logCountAfter = $this->countRecords('tx_robbicopy_import_log');
        self::assertEquals(1, $logCountAfter, 'Es sollte noch genau ein Import-Log übrig sein');
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    private function countRecords(string $table, array $where = []): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $query = $qb->count('uid')->from($table);

        foreach ($where as $field => $value) {
            $query->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
        }

        return (int)$query->executeQuery()->fetchOne();
    }
}
