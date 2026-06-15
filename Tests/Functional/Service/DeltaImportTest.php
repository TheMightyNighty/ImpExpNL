<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\RobbiCopy\Service\ExportService;
use Robbi\RobbiCopy\Service\ImportService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional-Test: Delta-Import, Duplikaterkennung, Konflikte.
 */
class DeltaImportTest extends FunctionalTestCase
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
    public function deltaImportSkipsIdenticalRecords(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_delta.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        // Erster Import (Voll)
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        $pageCountAfterFirst = $this->countRecords('pages', ['deleted' => 0]);

        // Zweiter Import: Delta — identische Records sollten übersprungen werden
        $importService->runImport($tempFile, 0, [
            'workspaceId' => 0,
            'deltaMode' => true,
        ]);

        $pageCountAfterDelta = $this->countRecords('pages', ['deleted' => 0]);

        // Bei Delta dürfen KEINE neuen Seiten angelegt werden (alles identisch)
        self::assertEquals(
            $pageCountAfterFirst,
            $pageCountAfterDelta,
            'Delta-Import hat neue Records angelegt obwohl alles identisch ist'
        );
    }

    #[Test]
    public function deltaImportUpdatesChangedRecords(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_delta_update.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        // Erster Import
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // JSON manipulieren: Titel einer Seite ändern
        $data = json_decode($json, true);
        foreach ($data['pages'] as &$page) {
            if ((int)$page['uid'] === 2) {
                $page['title'] = 'Über uns AKTUALISIERT';
            }
        }
        // Checksum neu berechnen
        $data['_meta']['checksum'] = hash(
            'sha256',
            json_encode($data['pages']) . json_encode($data['tt_content'] ?? [])
        );
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT));

        // Delta-Import
        $importService->runImport($tempFile, 0, [
            'workspaceId' => 0,
            'deltaMode' => true,
        ]);

        // Prüfe: Der importierte Record muss den neuen Titel haben
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('title')->from('pages')
            ->where($qb->expr()->eq('tx_robbicopy_remote_uid', 2))
            ->executeQuery()->fetchAssociative();

        self::assertNotFalse($row);
        self::assertEquals('Über uns AKTUALISIERT', $row['title']);
    }

    #[Test]
    public function conflictSkipLeavesLocalDataUntouched(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_conflict.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        // Import
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Lokal den Titel ändern + tstamp erhöhen (simuliert lokale Bearbeitung)
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update('pages', [
            'title' => 'LOKAL GEÄNDERT',
            'tstamp' => time() + 3600, // In der Zukunft → neuer als Export
        ], ['tx_robbicopy_remote_uid' => 2]);

        // Delta-Import mit conflict=skip
        $importService->runImport($tempFile, 0, [
            'workspaceId' => 0,
            'deltaMode' => true,
            'conflict' => 'skip',
        ]);

        // Prüfe: Lokaler Titel muss erhalten geblieben sein
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('title')->from('pages')
            ->where($qb->expr()->eq('tx_robbicopy_remote_uid', 2))
            ->executeQuery()->fetchAssociative();

        self::assertEquals(
            'LOKAL GEÄNDERT',
            $row['title'],
            'conflict=skip sollte die lokale Änderung erhalten'
        );
    }

    #[Test]
    public function dryRunDoesNotWriteToDatabase(): void
    {

        $pageCountBefore = $this->countRecords('pages');

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_dryrun.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['dryRun' => true]);

        $pageCountAfter = $this->countRecords('pages');

        self::assertEquals(
            $pageCountBefore,
            $pageCountAfter,
            'Dry-Run hat Daten in die Datenbank geschrieben!'
        );
    }

    // =========================================================================
    // Hilfsmethoden
    // =========================================================================

    private function countRecords(string $table, array $where = []): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $query = $qb->count('uid')->from($table);
        foreach ($where as $f => $v) {
            $query->andWhere($qb->expr()->eq($f, $qb->createNamedParameter($v)));
        }
        return (int)$query->executeQuery()->fetchOne();
    }
}
