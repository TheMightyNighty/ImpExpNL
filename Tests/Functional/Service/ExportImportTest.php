<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Functional\Service;

use Robbi\RobbiCopy\Service\ExportService;
use Robbi\RobbiCopy\Service\ImportService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional-Test: Vollständiger Export → Import → Verifikation.
 *
 * Dieser Test:
 *  1. Lädt Fixture-Daten in eine frische Test-Datenbank
 *  2. Exportiert den Seitenbaum als JSON
 *  3. Importiert die JSON unter einer neuen PID
 *  4. Prüft ob alle Records korrekt angelegt wurden
 *  5. Prüft ob Links umgeschrieben wurden
 *
 * Die Test-Datenbank wird pro Test automatisch erstellt und danach gelöscht.
 */
class ExportImportTest extends FunctionalTestCase
{
    /**
     * Extensions die für den Test geladen werden müssen.
     * Hier nur robbi_copy — für GSB-Tests würde man gsb_core ergänzen.
     */
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
    public function exportCreatesValidJson(): void
    {
        // Fixture-Daten laden

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);
        $data = json_decode($json, true);

        // JSON muss gültig sein
        self::assertNotNull($data, 'JSON ist ungültig');
        self::assertIsArray($data['pages']);
        self::assertIsArray($data['tt_content']);

        // Metadaten prüfen
        self::assertArrayHasKey('_meta', $data);
        self::assertArrayHasKey('checksum', $data['_meta']);
        self::assertEquals('4.14.0', $data['_meta']['export_version']);

        // Alle nicht-versteckten Seiten müssen drin sein (uid 1-4, nicht uid 5 hidden)
        $exportedUids = array_column($data['pages'], 'uid');
        self::assertContains(1, $exportedUids, 'Startseite fehlt');
        self::assertContains(2, $exportedUids, 'Über uns fehlt');
        self::assertContains(3, $exportedUids, 'Kontakt fehlt');
        self::assertContains(4, $exportedUids, 'Team fehlt');
        self::assertNotContains(5, $exportedUids, 'Versteckte Seite sollte nicht exportiert werden');

        // Inhalte prüfen
        self::assertNotEmpty($data['tt_content']);
        $contentUids = array_column($data['tt_content'], 'uid');
        self::assertContains(10, $contentUids);
        self::assertContains(12, $contentUids);
    }

    #[Test]
    public function exportWithIncludeHiddenContainsHiddenPages(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1, ['includeHidden' => true]);
        $data = json_decode($json, true);

        $exportedUids = array_column($data['pages'], 'uid');
        self::assertContains(5, $exportedUids, 'Versteckte Seite muss bei includeHidden enthalten sein');
    }

    #[Test]
    public function exportWithDepthLimitsRecursion(): void
    {

        $exportService = $this->get(ExportService::class);
        // depth=1: Nur Startseite + direkte Kinder (uid 2,3), nicht Enkel (uid 4)
        $json = $exportService->exportTree(1, ['depth' => 1]);
        $data = json_decode($json, true);

        $exportedUids = array_column($data['pages'], 'uid');
        self::assertContains(1, $exportedUids);
        self::assertContains(2, $exportedUids);
        self::assertContains(3, $exportedUids);
        self::assertNotContains(4, $exportedUids, 'Team (Ebene 3) sollte bei depth=1 nicht enthalten sein');
    }

    #[Test]
    public function importCreatesRecordsUnderTargetPid(): void
    {

        // Export
        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        // JSON in Temp-Datei schreiben
        $tempFile = $this->instancePath . '/var/test_export.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        // Import unter PID 0 (Wurzel) — erzeugt neuen Baum
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Prüfen: Neue Seiten müssen existieren mit tx_robbicopy_remote_uid
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $result = $connection->select(['uid', 'title', 'tx_robbicopy_remote_uid'], 'pages', [
            'tx_robbicopy_remote_uid' => 2, // "Über uns" hatte remote_uid=2
        ]);
        $row = $result->fetchAssociative();

        self::assertNotFalse($row, 'Importierte Seite "Über uns" nicht gefunden');
        self::assertEquals('Über uns', $row['title']);
        self::assertNotEquals(2, $row['uid'], 'Importierte Seite muss eine neue UID haben');
    }

    #[Test]
    public function importRewritesInternalLinks(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);

        $tempFile = $this->instancePath . '/var/test_links.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Content uid=10 hatte header="Willkommen" und bodytext mit "t3://page?uid=3"
        // Nach Import muss der Link auf die NEUE UID der Kontaktseite zeigen
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('bodytext')->from('tt_content')
            ->where(
                $qb->expr()->eq('header', $qb->createNamedParameter('Willkommen')),
                $qb->expr()->neq('uid', $qb->createNamedParameter(10, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchAssociative();

        self::assertNotFalse($row, 'Importierter Content "Willkommen" nicht gefunden');
        // Der Link darf NICHT mehr auf uid=3 zeigen (das war die Quell-UID)
        self::assertStringNotContainsString('t3://page?uid=3', $row['bodytext'],
            'Link wurde nicht umgeschrieben — zeigt noch auf alte UID');
        // Er muss auf t3://page?uid=<neue UID> zeigen
        self::assertMatchesRegularExpression('/t3:\/\/page\?uid=\d+/', $row['bodytext'],
            'Umgeschriebener Link hat falsches Format');
    }

    #[Test]
    public function exportImportPreservesSorting(): void
    {

        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);
        $data = json_decode($json, true);

        // Sortierung aus dem Export lesen
        $sortingByUid = [];
        foreach ($data['pages'] as $page) {
            $sortingByUid[(int)$page['uid']] = (int)$page['sorting'];
        }

        // "Über uns" (uid=2) muss sorting=256, "Kontakt" (uid=3) muss sorting=512 haben
        self::assertEquals(256, $sortingByUid[2]);
        self::assertEquals(512, $sortingByUid[3]);
    }
}
