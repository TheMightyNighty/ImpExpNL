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

namespace Robbi\ImpExpNL\Tests\Functional\Profile;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\RollbackService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Profil-Contract: die Mindestgarantien, die ein Inhaltstyp/Profil erfüllen muss,
 * um als „unterstützt" zu gelten. Ein konkretes Profil leitet hiervon ab, liefert
 * Fixtures + erwartete Records und schaltet optionale Klauseln (Link-Rewrite,
 * Kategorie-Mapping, FAL) durch Überschreiben der verify*-Methoden frei.
 *
 * Pflichtklauseln: Export enthält alle Records · Import bildet alle Records ab ·
 * Delta-Re-Import ist idempotent · Rollback entfernt alles.
 *
 * Diese Klasse trägt absichtlich kein „Test"-Suffix und ist abstrakt, damit PHPUnit
 * sie nicht direkt einsammelt.
 */
abstract class AbstractProfileContract extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private string $exportFile = '';

    /**
     * CSV-Fixtures des Profils (ohne be_users – wird immer geladen).
     *
     * @return string[]
     */
    abstract protected function profileFixtures(): array;

    /**
     * Erwartete Quell-UIDs je Tabelle, die exportiert und importiert werden müssen.
     *
     * @return array<string, int[]>
     */
    abstract protected function expectedSourceUids(): array;

    protected function rootPageUid(): int
    {
        return 1;
    }

    protected function profileName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->profileFixtures() as $csv) {
            $this->importCSVDataSet($csv);
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree($this->rootPageUid());
        $this->exportFile = $this->instancePath . '/var/profile_' . $this->profileName() . '.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function runImport(array $options = []): array
    {
        return $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0] + $options);
    }

    protected function liveRecordExists(string $table, int $uid): bool
    {
        // Standard-Restrictions (inkl. DeletedRestriction) – gelöschte Records zählen nicht.
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        return (bool)$qb->count('uid')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    // --- Pflichtklauseln -----------------------------------------------------

    #[Test]
    public function exportContainsAllExpectedRecords(): void
    {
        $data = json_decode((string)file_get_contents($this->exportFile), true);
        self::assertIsArray($data);

        foreach ($this->expectedSourceUids() as $table => $uids) {
            self::assertArrayHasKey($table, $data, "Export enthält Tabelle '$table' nicht");
            $exported = array_column($data[$table], 'uid');
            foreach ($uids as $uid) {
                self::assertContains($uid, $exported, "Export: Record $table:$uid fehlt");
            }
        }
    }

    #[Test]
    public function importMapsAllExpectedRecords(): void
    {
        $this->runImport();

        foreach ($this->expectedSourceUids() as $table => $uids) {
            foreach ($uids as $uid) {
                $target = $this->resolveTargetUid($table, $uid);
                self::assertNotNull($target, "Import: kein Mapping für $table:$uid");
                self::assertTrue($this->liveRecordExists($table, $target), "Import: Ziel-Record $table:$target fehlt");
            }
            self::assertSame(
                count($uids),
                $this->countMappedRecords($table),
                "Import: unerwartete Anzahl gemappter $table-Records"
            );
        }
    }

    #[Test]
    public function deltaReimportIsIdempotent(): void
    {
        $this->runImport();
        $before = [];
        foreach (array_keys($this->expectedSourceUids()) as $table) {
            $before[$table] = $this->countMappedRecords($table);
        }

        $result = $this->runImport(['deltaMode' => true]);

        self::assertSame(0, (int)($result['stats']['new'] ?? -1), 'Delta-Re-Import legt neue Records an (nicht idempotent)');
        foreach ($before as $table => $count) {
            self::assertSame($count, $this->countMappedRecords($table), "Delta: Mapping-Anzahl für $table verändert");
        }
    }

    #[Test]
    public function rollbackRemovesEverything(): void
    {
        $this->runImport();

        $targets = [];
        foreach ($this->expectedSourceUids() as $table => $uids) {
            foreach ($uids as $uid) {
                $targets[$table][] = $this->resolveTargetUid($table, $uid);
            }
        }

        $this->get(RollbackService::class)->runRollback();

        foreach ($targets as $table => $uidList) {
            self::assertSame(0, $this->countMappedRecords($table), "Rollback: Mapping für $table nicht geleert");
            foreach ($uidList as $target) {
                self::assertFalse(
                    $this->liveRecordExists($table, (int)$target),
                    "Rollback: Record $table:$target existiert noch"
                );
            }
        }
    }

    // --- Optionale Klauseln (per Default übersprungen) -----------------------

    #[Test]
    public function linkRewriteRemapsReferences(): void
    {
        $this->runImport();
        $this->verifyLinkRewrite();
    }

    #[Test]
    public function categoryRelationsAreRemapped(): void
    {
        $this->runImport();
        $this->verifyCategoryMapping();
    }

    #[Test]
    public function falReferencesArePreserved(): void
    {
        $this->runImport();
        $this->verifyFalReferences();
    }

    protected function verifyLinkRewrite(): void
    {
        self::markTestSkipped('Profil deklariert kein Link-Rewriting.');
    }

    protected function verifyCategoryMapping(): void
    {
        self::markTestSkipped('Profil deklariert kein Kategorie-Mapping.');
    }

    protected function verifyFalReferences(): void
    {
        self::markTestSkipped('Profil deklariert keine FAL-Referenzen.');
    }
}
