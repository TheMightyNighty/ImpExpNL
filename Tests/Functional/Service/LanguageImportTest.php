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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Sprach-/l10n-Test: Übersetzungen (Seiten + Inhalte) werden importiert und ihre
 * Verknüpfungen (l10n_parent / l18n_parent) auf die neuen Eltern-UIDs aufgelöst.
 *
 * Deckt zugleich den Crash ab, den Relations-Container-Felder (inline/file/category,
 * nur Zähler) im DataMapProcessor bei Übersetzungen auslösten.
 */
class LanguageImportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_l10n.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_l10n.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1);
        $this->exportFile = $this->instancePath . '/var/lang.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
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

    /**
     * @return array<string, mixed>|false
     */
    private function record(string $table, int $uid): array|false
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
    }

    #[Test]
    public function pageTranslationKeepsRemappedL10nParent(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newDefault = $this->importedUid('pages', 2);
        $newTranslation = $this->importedUid('pages', 3);
        self::assertNotNull($newDefault, 'Default-Seite nicht importiert');
        self::assertNotNull($newTranslation, 'Übersetzte Seite nicht importiert');

        $row = $this->record('pages', $newTranslation);
        self::assertNotFalse($row);
        self::assertSame(1, (int)$row['sys_language_uid'], 'Sprache der Übersetzung nicht erhalten');
        self::assertSame($newDefault, (int)$row['l10n_parent'], 'l10n_parent wurde nicht auf die neue Default-Seite umgeschrieben');
    }

    #[Test]
    public function contentTranslationKeepsRemappedL18nParent(): void
    {
        $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        $newDefault = $this->importedUid('tt_content', 10);
        $newTranslation = $this->importedUid('tt_content', 11);
        self::assertNotNull($newDefault, 'Default-Inhalt nicht importiert');
        self::assertNotNull($newTranslation, 'Übersetzter Inhalt nicht importiert');

        $row = $this->record('tt_content', $newTranslation);
        self::assertNotFalse($row);
        self::assertSame(1, (int)$row['sys_language_uid'], 'Sprache des Inhalts nicht erhalten');
        self::assertSame($newDefault, (int)$row['l18n_parent'], 'l18n_parent wurde nicht auf den neuen Default-Inhalt umgeschrieben');
    }
}
