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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Vertrauensfeature: Die Dry-Run-Prognose (neu/geändert/identisch) muss exakt dem
 * tatsächlichen Effekt des echten Imports (new/updated/skipped) entsprechen.
 */
class DryRunMatchesImportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/dryrun.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    /**
     * Prüft die Invariante für einen konkreten Import: erst Dry-Run (verändert nichts),
     * dann derselbe echte Import – die Summen müssen übereinstimmen.
     */
    private function assertDryRunMatchesImport(int $targetPid, bool $deltaMode): void
    {
        $importService = $this->get(ImportService::class);

        $dry = $importService->runImport($this->exportFile, $targetPid, [
            'workspaceId' => 0,
            'deltaMode' => $deltaMode,
            'dryRun' => true,
        ]);
        $diff = $dry['diff'];
        $predNew = $diff['pages']['new'] + $diff['tt_content']['new'];
        $predChanged = $diff['pages']['changed'] + $diff['tt_content']['changed'];
        $predIdentical = $diff['pages']['identical'] + $diff['tt_content']['identical'];

        $real = $importService->runImport($this->exportFile, $targetPid, [
            'workspaceId' => 0,
            'deltaMode' => $deltaMode,
        ]);
        $stats = $real['stats'];

        self::assertSame($predNew, $stats['new'], 'Dry-Run-Prognose "neu" weicht vom echten Import ab');
        self::assertSame($predChanged, $stats['updated'], 'Dry-Run-Prognose "geändert" weicht vom echten Import ab');
        self::assertSame($predIdentical, $stats['skipped'], 'Dry-Run-Prognose "identisch" weicht vom echten Import ab');
    }

    #[Test]
    public function freshImportMatchesDryRun(): void
    {
        $this->assertDryRunMatchesImport(0, false);

        // Plausibilität: ein frischer Import legt Records an (Mappings vorhanden).
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $mapped = (int)$qb->count('uid')->from('tx_impexpnl_uid_map')->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $mapped, 'Frischer Import hat keine Mappings erzeugt');
    }

    #[Test]
    public function identicalDeltaReimportMatchesDryRun(): void
    {
        // Erstimport legt den Baum + Mappings an.
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        // Delta-Re-Import derselben Datei: Dry-Run-Prognose muss dem Effekt entsprechen
        // (keine neuen Records, alles identisch oder geändert je nach Slug-Regenerierung).
        $this->assertDryRunMatchesImport(0, true);
    }

    #[Test]
    public function deltaWithLocalChangeMatchesDryRun(): void
    {
        // Erstimport.
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        // Lokale Änderung an einer importierten Seite (über das Mapping ermittelt).
        $mapQb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $targetUid = (int)$mapQb->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where(
                $mapQb->expr()->eq('table_name', $mapQb->createNamedParameter('pages')),
                $mapQb->expr()->eq('source_uid', $mapQb->createNamedParameter(2, \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $targetUid, 'Mapping für Quell-Seite 2 nicht gefunden');

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')
            ->update('pages', ['title' => 'LOKAL GEÄNDERT'], ['uid' => $targetUid]);

        // Delta-Import (overwrite): Dry-Run-Prognose muss dem Effekt entsprechen.
        $this->assertDryRunMatchesImport(0, true);
    }

    #[Test]
    public function hiddenRecordsDryRunMatchesImport(): void
    {
        // Export inkl. versteckter Records (pages.csv enthält die versteckte Seite uid=5).
        $json = $this->get(ExportService::class)->exportTree(1, ['includeHidden' => true]);
        $file = $this->instancePath . '/var/dryrun_hidden.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $json);

        $data = json_decode($json, true);
        self::assertContains(5, array_column($data['pages'], 'uid'), 'Versteckte Seite fehlt im includeHidden-Export');

        $importService = $this->get(ImportService::class);
        $dry = $importService->runImport($file, 0, ['workspaceId' => 0, 'dryRun' => true]);
        $diff = $dry['diff'];
        $predNew = $diff['pages']['new'] + $diff['tt_content']['new'];

        $real = $importService->runImport($file, 0, ['workspaceId' => 0]);

        self::assertSame($predNew, $real['stats']['new'], 'Dry-Run-Prognose weicht bei versteckten Records ab');

        // Die versteckte Seite muss tatsächlich (versteckt) importiert worden sein.
        $newHidden = (int)GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map')
            ->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where("table_name = 'pages'", 'source_uid = 5')
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $newHidden, 'Versteckte Seite wurde nicht importiert');
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $hiddenFlag = (int)$qb->select('hidden')->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newHidden, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame(1, $hiddenFlag, 'Hidden-Flag ging beim Import verloren');
    }

    #[Test]
    public function conflictSkipDryRunMatchesImport(): void
    {
        $importService = $this->get(ImportService::class);
        $importService->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        // Konflikt erzeugen: einen importierten Inhalt inhaltlich ändern und in die
        // Zukunft datieren -> im Dry-Run "geändert", im echten Import (skip) "conflict_skipped".
        $mapQb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map');
        $targetUid = (int)$mapQb->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where(
                $mapQb->expr()->eq('table_name', $mapQb->createNamedParameter('tt_content')),
                $mapQb->expr()->eq('source_uid', $mapQb->createNamedParameter(10, \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $targetUid, 'Mapping für Quell-Inhalt 10 nicht gefunden');

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['header' => 'LOKAL GEÄNDERT', 'tstamp' => time() + 100000], ['uid' => $targetUid]);

        $dry = $importService->runImport($this->exportFile, 0, ['workspaceId' => 0, 'deltaMode' => true, 'dryRun' => true]);
        $diff = $dry['diff'];
        $predNew = $diff['pages']['new'] + $diff['tt_content']['new'];
        $predChanged = $diff['pages']['changed'] + $diff['tt_content']['changed'];
        $predIdentical = $diff['pages']['identical'] + $diff['tt_content']['identical'];

        $real = $importService->runImport($this->exportFile, 0, ['workspaceId' => 0, 'deltaMode' => true, 'conflict' => 'skip']);
        $stats = $real['stats'];

        self::assertGreaterThanOrEqual(1, $stats['conflict_skipped'], 'Es wurde kein Konflikt übersprungen');
        self::assertSame($predNew, $stats['new'], 'Prognose "neu" weicht ab');
        self::assertSame($predIdentical, $stats['skipped'], 'Prognose "identisch" weicht ab');
        // Bei conflict=skip teilt sich die Prognose "geändert" auf echte Updates und
        // wegen Konflikt übersprungene Records auf.
        self::assertSame(
            $predChanged,
            $stats['updated'] + $stats['conflict_skipped'],
            'Prognose "geändert" != tatsächlich aktualisiert + konfliktbedingt übersprungen'
        );
    }
}
