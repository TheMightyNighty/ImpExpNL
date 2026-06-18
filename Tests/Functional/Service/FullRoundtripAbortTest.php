<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Tests\Functional\TestDataGenerator;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Vollständiger Lauf über alle drei Phasen mit großem Datensatz:
 *   1. Import von 1000 Seiten.
 *   2. Export der importierten 1000 Seiten.
 *   3. Erneuter Import der 1000 Seiten, Abbruch in der Mitte, automatischer Rollback.
 *
 * Skalierbar über IMPEXPNL_PERF_PAGES (Standard 1000).
 */
class FullRoundtripAbortTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function importExportAndAbortedReimportWithRollback(): void
    {
        $pages = (int)(getenv('IMPEXPNL_PERF_PAGES') ?: 1000);
        $contentPerPage = (int)(getenv('IMPEXPNL_PERF_CONTENT') ?: 2);

        $data = TestDataGenerator::build($pages, $contentPerPage);
        $importFile = $this->instancePath . '/var/roundtrip_import.json';
        @mkdir(dirname($importFile), 0775, true);
        TestDataGenerator::writeFile($data, $importFile);

        $importService = $this->get(ImportService::class);

        // -------------------------------------------------------------------
        // Phase 1: Import von 1000 Seiten
        // -------------------------------------------------------------------
        $start = microtime(true);
        $importService->runImport($importFile, 0, ['workspaceId' => 0]);
        $importMs = (int)((microtime(true) - $start) * 1000);

        $importedPages = $this->countImported('pages');
        self::assertSame($pages, $importedPages, 'Phase 1: nicht alle Seiten importiert');
        $logsAfterImport = $this->countRecords('tx_impexpnl_import_log');
        self::assertSame(1, $logsAfterImport, 'Phase 1: genau ein Import-Log erwartet');

        // -------------------------------------------------------------------
        // Phase 2: Export der importierten 1000 Seiten
        // -------------------------------------------------------------------
        $rootUid = $this->findImportedRootUid();
        self::assertGreaterThan(0, $rootUid, 'Phase 2: importierte Wurzelseite nicht gefunden');

        $start = microtime(true);
        $json = $this->get(ExportService::class)->exportTree($rootUid);
        $exportMs = (int)((microtime(true) - $start) * 1000);

        $exported = json_decode($json, true);
        self::assertIsArray($exported);
        $exportedPageCount = count($exported['pages'] ?? []);
        self::assertSame($pages, $exportedPageCount, 'Phase 2: Export enthält nicht alle 1000 Seiten');

        $exportFile = $this->instancePath . '/var/roundtrip_export.json';
        file_put_contents($exportFile, $json);

        // -------------------------------------------------------------------
        // Phase 3: Erneuter Import mit Abbruch in der Mitte + Rollback
        // -------------------------------------------------------------------
        $pagesBeforeReimport = $this->countRecords('pages', ['deleted' => 0]);
        $contentBeforeReimport = $this->countRecords('tt_content', ['deleted' => 0]);

        // Abbruch nach dem ersten Seiten-Batch (Batch-Größe 500 -> Abbruch bei 1/2).
        $abortAfterFirstBatch = static function (string $label, int $index, int $total): void {
            if (str_starts_with($label, 'pages') && $index >= 1 && $index < $total) {
                throw new \RuntimeException('Simulierter Abbruch in der Mitte des Imports');
            }
        };

        $aborted = false;
        try {
            $importService->runImport($importFile, 0, [
                'workspaceId' => 0,
                'onProgress' => $abortAfterFirstBatch,
            ]);
        } catch (\RuntimeException $e) {
            $aborted = true;
            self::assertStringContainsString('Simulierter Abbruch', $e->getMessage());
        }
        self::assertTrue($aborted, 'Phase 3: Import hätte abbrechen müssen');

        // Auto-Rollback (Standardkonfiguration) muss den Teilimport entfernt haben.
        $pagesAfterRollback = $this->countRecords('pages', ['deleted' => 0]);
        $contentAfterRollback = $this->countRecords('tt_content', ['deleted' => 0]);

        self::assertSame(
            $pagesBeforeReimport,
            $pagesAfterRollback,
            'Phase 3: Teilimport wurde nicht vollständig zurückgerollt (Seiten)'
        );
        self::assertSame(
            $contentBeforeReimport,
            $contentAfterRollback,
            'Phase 3: Teilimport wurde nicht vollständig zurückgerollt (Inhalte)'
        );

        // Der abgebrochene Import darf kein Log hinterlassen (Rollback löscht es).
        self::assertSame(
            1,
            $this->countRecords('tx_impexpnl_import_log'),
            'Phase 3: abgebrochener Import wurde nicht aus dem Log entfernt'
        );

        fwrite(STDERR, sprintf(
            "\n[Roundtrip] Import %d Seiten: %d ms | Export: %d ms | Abbruch+Rollback ok | Peak %s MB\n",
            $pages,
            $importMs,
            $exportMs,
            number_format(memory_get_peak_usage(true) / 1048576, 1)
        ));
    }

    private function findImportedRootUid(): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('tx_impexpnl_remote_uid', $qb->createNamedParameter(1)))
            ->executeQuery()->fetchOne();
    }

    private function countImported(string $table): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from($table)
            ->where($qb->expr()->gt('tx_impexpnl_remote_uid', $qb->createNamedParameter(0)))
            ->executeQuery()->fetchOne();
    }

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
