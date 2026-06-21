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
use Robbi\ImpExpNL\Tests\Functional\TestDataGenerator;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Performance-Baseline als Regressionsschutz (kein Marketing). Misst je einen Lauf
 * Export-/Import-/Rollback-Dauer + Speicher-Peak für eine Größenklasse und gibt eine
 * konstant formatierte Zeile aus, die je Release in Documentation/PERFORMANCE.md
 * festgehalten wird.
 *
 * Standard ist die kleine Klasse (CI-tauglich). Größere Klassen + Format per Env:
 *   IMPEXPNL_PERF_SIZE=small|medium|large   (Default small)
 *   IMPEXPNL_PERF_FORMAT=json|jsonl         (Default json)
 *
 * Pro Lauf nur eine Klasse, damit der Speicher-Peak nicht durch Vorläufe verfälscht wird.
 */
class PerformanceBaselineTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    /** Größenklasse → [Seiten, Inhalte pro Seite]. */
    private const SIZES = [
        'small' => [100, 5],
        'medium' => [1000, 5],
        'large' => [10000, 2],
    ];

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
    public function measuresBaselineForSizeClass(): void
    {
        $size = (string)(getenv('IMPEXPNL_PERF_SIZE') ?: 'small');
        [$pages, $contentPerPage] = self::SIZES[$size] ?? self::SIZES['small'];
        $jsonl = getenv('IMPEXPNL_PERF_FORMAT') === 'jsonl';

        $data = TestDataGenerator::build($pages, $contentPerPage);
        $file = $this->instancePath . '/var/perf_baseline.' . ($jsonl ? 'jsonl' : 'json');
        @mkdir(dirname($file), 0775, true);
        TestDataGenerator::writeFile($data, $file, $jsonl);

        // --- Import ---
        $t0 = microtime(true);
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);
        $importMs = (int)((microtime(true) - $t0) * 1000);

        self::assertSame($pages, $this->countMappedRecords('pages'), 'Nicht alle Seiten importiert');
        self::assertSame($pages * $contentPerPage, $this->countMappedRecords('tt_content'), 'Nicht alle Inhalte importiert');

        // --- Export des importierten Baums ---
        $rootNew = $this->resolveTargetUid('pages', 1);
        self::assertNotNull($rootNew, 'Wurzel der importierten Daten nicht gefunden');
        $t1 = microtime(true);
        $json = $this->get(ExportService::class)->exportTree($rootNew);
        $exportMs = (int)((microtime(true) - $t1) * 1000);
        self::assertNotSame('', $json);

        // --- Rollback ---
        $t2 = microtime(true);
        $this->get(RollbackService::class)->runRollback();
        $rollbackMs = (int)((microtime(true) - $t2) * 1000);
        self::assertSame(0, $this->countMappedRecords('pages'), 'Rollback hat nicht aufgeräumt');

        fwrite(STDERR, sprintf(
            "\n[Baseline] %-6s %-5s | Seiten %5d | Inhalte %6d | Import %7d ms | Export %6d ms | Rollback %7d ms | Peak %6s MB\n",
            $size,
            $jsonl ? 'JSONL' : 'JSON',
            $pages,
            $pages * $contentPerPage,
            $importMs,
            $exportMs,
            $rollbackMs,
            number_format(memory_get_peak_usage(true) / 1048576, 1)
        ));
    }
}
