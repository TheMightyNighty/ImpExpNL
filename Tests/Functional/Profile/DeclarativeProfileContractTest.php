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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\RollbackService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * YAML-getriebener Profil-Contract: liest die Profil-Definitionen aus profiles.yaml und
 * prüft je Profil generisch die vier Pflichtklauseln (Export enthält alle Records · Import
 * mappt alle · Delta idempotent · Rollback leert). So lässt sich ein neues Profil – auch
 * für eine Ziel-Extension wie EXT:redirects – ohne eigene PHP-Subklasse absichern.
 *
 * Profil-spezifische Sonderlogik (Link-Rewrite, FAL, Kategorie-MM) bleibt in den dedizierten
 * Contract-Subklassen, weil sie eigene Assertions erfordert.
 */
class DeclarativeProfileContractTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    // Union aller von den Profilen benötigten Core-Extensions (Properties sind klassenfix).
    protected array $coreExtensionsToLoad = ['frontend', 'redirects'];

    protected array $testExtensionsToLoad = ['typo3conf/ext/imp_exp_nl'];

    /**
     * @return array<string, array{0:string, 1:array<string,mixed>}>
     */
    public static function profileProvider(): array
    {
        $spec = Yaml::parseFile(__DIR__ . '/profiles.yaml');
        $cases = [];
        foreach (($spec['profiles'] ?? []) as $name => $def) {
            $cases[$name] = [$name, $def];
        }
        return $cases;
    }

    /**
     * @param array<string, mixed> $def
     */
    #[Test]
    #[DataProvider('profileProvider')]
    public function profileFulfillsMandatoryContract(string $name, array $def): void
    {
        foreach (($def['coreExtensions'] ?? []) as $ext) {
            if (!ExtensionManagementUtility::isLoaded((string)$ext)) {
                self::markTestSkipped(sprintf('Profil "%s" benötigt Extension "%s" (nicht geladen).', $name, $ext));
            }
        }

        foreach ($def['fixtures'] as $csv) {
            $this->importCSVDataSet(__DIR__ . '/../Fixtures/' . $csv);
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        /** @var array<string, int[]> $expected */
        $expected = $def['expected'];

        // Export.
        $exportFile = $this->instancePath . '/var/decl_' . $name . '.json';
        @mkdir(dirname($exportFile), 0775, true);
        file_put_contents($exportFile, $this->get(ExportService::class)->exportTree((int)($def['rootPage'] ?? 1)));

        // Klausel 1: Export enthält alle erwarteten Records.
        $data = json_decode((string)file_get_contents($exportFile), true);
        self::assertIsArray($data);
        foreach ($expected as $table => $uids) {
            self::assertArrayHasKey($table, $data, "[$name] Export enthält Tabelle '$table' nicht");
            $exported = array_column($data[$table], 'uid');
            foreach ($uids as $uid) {
                self::assertContains($uid, $exported, "[$name] Export: Record $table:$uid fehlt");
            }
        }

        // Klausel 2: Import bildet alle Records ab.
        $importResult = $this->get(ImportService::class)->runImport($exportFile, 0, ['workspaceId' => 0]);
        $importId = (string)($importResult['importId'] ?? '');
        $targets = [];
        foreach ($expected as $table => $uids) {
            foreach ($uids as $uid) {
                $target = $this->resolveTargetUid($table, $uid);
                self::assertNotNull($target, "[$name] Import: kein Mapping für $table:$uid");
                self::assertTrue($this->liveRecordExists($table, $target), "[$name] Import: Ziel-Record $table:$target fehlt");
                $targets[$table][] = $target;
            }
            self::assertSame(count($uids), $this->countMappedRecords($table), "[$name] Import: unerwartete Anzahl gemappter $table-Records");
        }

        // Klausel 3: Delta-Re-Import ist idempotent — weder neue Kern-Records
        // (stats.new) noch neue Registry-Records (Mapping-Anzahl bleibt konstant).
        if ($def['deltaIdempotent'] ?? true) {
            $result = $this->get(ImportService::class)->runImport($exportFile, 0, ['workspaceId' => 0, 'deltaMode' => true]);
            self::assertSame(0, (int)($result['stats']['new'] ?? -1), "[$name] Delta-Re-Import legt neue Records an (nicht idempotent)");
            foreach ($expected as $table => $uids) {
                self::assertSame(count($uids), $this->countMappedRecords($table), "[$name] Delta-Re-Import hat $table-Mappings dupliziert");
            }
        }

        // Klausel 4: Rollback entfernt alle importierten Records. Gezielt den Import aus
        // Klausel 2 zurückrollen (der idempotente Delta-Lauf wäre der „letzte" und ein No-Op).
        $this->get(RollbackService::class)->runRollback($importId, true);
        foreach ($expected as $table => $uids) {
            self::assertSame(0, $this->countMappedRecords($table), "[$name] Rollback: Mapping für $table nicht geleert");
        }
        foreach ($targets as $table => $uidList) {
            foreach ($uidList as $target) {
                self::assertFalse($this->liveRecordExists($table, (int)$target), "[$name] Rollback: Record $table:$target existiert noch");
            }
        }
    }

    private function liveRecordExists(string $table, int $uid): bool
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        return (bool)$qb->count('uid')->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }
}
