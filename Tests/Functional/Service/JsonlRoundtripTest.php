<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Export im JSONL-Format und anschließender Import (Round-Trip) inkl.
 * Integritätsprüfung.
 */
class JsonlRoundtripTest extends FunctionalTestCase
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
    public function jsonlExportAndImportRoundtrip(): void
    {
        $file = $this->instancePath . '/var/export.jsonl';
        @mkdir(dirname($file), 0775, true);

        $this->get(ExportService::class)->runExport(1, $file, ['jsonl' => true]);

        self::assertFileExists($file);
        $handle = fopen($file, 'r');
        $firstLine = $handle !== false ? (string)fgets($handle) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        self::assertStringContainsString('_meta', $firstLine, 'Erste JSONL-Zeile muss _meta enthalten');

        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $imported = (int)$qb->count('uid')->from('tx_impexpnl_uid_map')
            ->where($qb->expr()->eq('table_name', $qb->createNamedParameter('pages')))
            ->executeQuery()->fetchOne();

        self::assertGreaterThan(0, $imported, 'JSONL-Import hat keine Seiten angelegt');
    }
}
