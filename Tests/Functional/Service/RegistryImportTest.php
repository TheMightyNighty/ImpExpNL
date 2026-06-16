<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional-Test der Table-Registry: Kategorie-Zuordnungen (MM) werden über den
 * Kategorie-Pfad exportiert und auf dem Ziel wieder zugeordnet.
 */
class RegistryImportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function categoryRelationIsRemappedToNewContent(): void
    {
        $exportService = $this->get(ExportService::class);
        $json = $exportService->exportTree(1);
        $data = json_decode($json, true);

        self::assertArrayHasKey('sys_category_record_mm_with_paths', $data, 'Kategorie-MM mit Pfaden fehlt im Export');

        $tempFile = $this->instancePath . '/var/registry.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);

        $this->get(ImportService::class)->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Neuer Content (remote_uid=10) muss die Kategorie "Digitalisierung" (uid_local=2) erhalten.
        $newContentUid = $this->importedUid('tt_content', 10);
        self::assertNotNull($newContentUid, 'Importierter Content (remote 10) nicht gefunden');

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        $relation = $qb->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newContentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content'))
            )
            ->executeQuery()->fetchAssociative();

        self::assertNotFalse($relation, 'Kategorie-Zuordnung fehlt für den importierten Content');
        self::assertSame(2, (int)$relation['uid_local'], 'Kategorie-Pfad wurde nicht korrekt aufgelöst');
    }

    private function importedUid(string $table, int $remoteUid): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')->from($table)
            ->where($qb->expr()->eq('tx_impexpnl_remote_uid', $qb->createNamedParameter($remoteUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        return $uid !== false ? (int)$uid : null;
    }
}
