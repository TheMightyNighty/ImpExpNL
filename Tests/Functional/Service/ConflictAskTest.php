<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Domain\ConflictStrategy;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional-Test der interaktiven Konflikt-Strategie (ask): Der Callback
 * entscheidet, ob ein lokal neuerer Record überschrieben wird.
 */
class ConflictAskTest extends FunctionalTestCase
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
    public function askCallbackDecliningKeepsLocalChange(): void
    {
        $tempFile = $this->prepareImportFile();
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        // Lokale Bearbeitung des importierten Records simulieren (neuerer Zeitstempel).
        $newUid = $this->importedUid(10);
        $this->applyLocalEdit($newUid, 'LOKAL GEAENDERT');

        // Delta-Import mit ask-Callback, der das Überschreiben ablehnt.
        $importService->runImport($tempFile, 0, [
            'workspaceId' => 0,
            'deltaMode' => true,
            'conflict' => ConflictStrategy::Ask,
            'onConflictAsk' => static fn(array $info): bool => false,
        ]);

        self::assertSame('LOKAL GEAENDERT', $this->headerOf($newUid), 'Lokale Änderung wurde trotz Ablehnung überschrieben');
    }

    #[Test]
    public function askCallbackAcceptingOverwritesLocalChange(): void
    {
        $tempFile = $this->prepareImportFile();
        $importService = $this->get(ImportService::class);
        $importService->runImport($tempFile, 0, ['workspaceId' => 0]);

        $newUid = $this->importedUid(10);
        $this->applyLocalEdit($newUid, 'LOKAL GEAENDERT');

        $importService->runImport($tempFile, 0, [
            'workspaceId' => 0,
            'deltaMode' => true,
            'conflict' => ConflictStrategy::Ask,
            'onConflictAsk' => static fn(array $info): bool => true,
        ]);

        self::assertSame('Willkommen', $this->headerOf($newUid), 'Record wurde trotz Zustimmung nicht überschrieben');
    }

    private function prepareImportFile(): string
    {
        $json = $this->get(ExportService::class)->exportTree(1);
        $tempFile = $this->instancePath . '/var/conflict.json';
        @mkdir(dirname($tempFile), 0775, true);
        file_put_contents($tempFile, $json);
        return $tempFile;
    }

    private function applyLocalEdit(int $uid, string $header): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['header' => $header, 'tstamp' => time() + 100000],
            ['uid' => $uid]
        );
    }

    private function importedUid(int $remoteUid): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->select('uid')->from('tt_content')
            ->where($qb->expr()->eq('tx_impexpnl_remote_uid', $qb->createNamedParameter($remoteUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    private function headerOf(int $uid): string
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        return (string)$qb->select('header')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }
}
