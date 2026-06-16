<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Tests\Functional\TestDataGenerator;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Importiert einen generierten Baum. Standardmäßig klein (schnell in CI),
 * über Umgebungsvariablen für lokale Lasttests skalierbar:
 *   IMPEXPNL_PERF_PAGES, IMPEXPNL_PERF_CONTENT, IMPEXPNL_PERF_FORMAT=jsonl
 */
class LargeTreeImportTest extends FunctionalTestCase
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
    public function importsGeneratedTree(): void
    {
        $pages = (int)(getenv('IMPEXPNL_PERF_PAGES') ?: 60);
        $contentPerPage = (int)(getenv('IMPEXPNL_PERF_CONTENT') ?: 4);
        $jsonl = getenv('IMPEXPNL_PERF_FORMAT') === 'jsonl';

        $data = TestDataGenerator::build($pages, $contentPerPage);
        $file = $this->instancePath . '/var/perf.' . ($jsonl ? 'jsonl' : 'json');
        @mkdir(dirname($file), 0775, true);
        TestDataGenerator::writeFile($data, $file, $jsonl);

        $start = microtime(true);
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);
        $durationMs = (int)((microtime(true) - $start) * 1000);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $importedPages = (int)$qb->count('uid')->from('pages')
            ->where($qb->expr()->gt('tx_impexpnl_remote_uid', $qb->createNamedParameter(0)))
            ->executeQuery()->fetchOne();

        self::assertSame($pages, $importedPages, 'Es wurden nicht alle Seiten importiert');

        fwrite(STDERR, sprintf(
            "\n[Perf] %d Seiten / %d Inhalte (%s) importiert in %d ms, Peak %s MB\n",
            $pages,
            $pages * $contentPerPage,
            $jsonl ? 'JSONL' : 'JSON',
            $durationMs,
            number_format(memory_get_peak_usage(true) / 1048576, 1)
        ));
    }
}
