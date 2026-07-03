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
use Robbi\ImpExpNL\Service\TableRegistryService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PID-Containment des Registry-Imports: Records ohne auflösbare Ziel-Seite
 * (Quell-PID nicht in der UID-Map) werden übersprungen statt mit der
 * unveränderten Quell-PID an eine beliebige Stelle des Zielsystems geschrieben.
 *
 * Läuft nur, wenn die Tabelle sys_redirect existiert (EXT:redirects installiert).
 */
class RegistryContainmentTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $schemaManager = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_redirect')->createSchemaManager();
        if (!$schemaManager->tablesExist(['sys_redirect'])) {
            self::markTestSkipped('Tabelle sys_redirect nicht vorhanden (EXT:redirects nicht installiert).');
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    private function redirectCount(): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('sys_redirect')->executeQuery()->fetchOne();
    }

    private function redirectData(int $sourcePid): array
    {
        return [
            'sys_redirect' => [[
                'uid' => 900,
                'pid' => $sourcePid,
                'source_host' => '*',
                'source_path' => '/alte-seite',
                'target' => 'https://example.org/',
                'deleted' => 0,
            ]],
        ];
    }

    #[Test]
    public function recordWithMappedPidIsImportedOntoTargetPage(): void
    {
        $uidMap = ['pages' => [77 => 1]];
        $this->get(TableRegistryService::class)->importRegisteredTables($this->redirectData(77), $uidMap);

        self::assertSame(1, $this->redirectCount(), 'Record mit gemappter PID muss importiert werden');
    }

    #[Test]
    public function recordWithUnmappablePidIsSkipped(): void
    {
        $uidMap = ['pages' => [77 => 1]];
        $this->get(TableRegistryService::class)->importRegisteredTables($this->redirectData(4711), $uidMap);

        self::assertSame(0, $this->redirectCount(), 'Record ohne auflösbare Ziel-Seite darf nicht geschrieben werden');
    }
}
