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
use Robbi\ImpExpNL\Command\ImportCommand;
use Robbi\ImpExpNL\Domain\ExitCode;
use Robbi\ImpExpNL\Exception\ConfigException;
use Robbi\ImpExpNL\Exception\ConflictException;
use Robbi\ImpExpNL\Exception\IntegrityException;
use Robbi\ImpExpNL\Exception\LockException;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportLockService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\ProfileService;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Differenzierte Exit-Codes für CI/CD: jede fachliche Fehlerart trägt ihren Code.
 */
class ExitCodeTest extends FunctionalTestCase
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
        $this->exportFile = $this->instancePath . '/var/exitcode.json';
        @mkdir(dirname($this->exportFile), 0775, true);
        file_put_contents($this->exportFile, $json);
    }

    #[Test]
    public function invalidProfileYieldsConfigCode(): void
    {
        try {
            $this->get(ProfileService::class)->loadProfile('gibt_es_nicht');
            self::fail('Erwartete ConfigException');
        } catch (ConfigException $e) {
            self::assertSame(ExitCode::INVALID_CONFIG, $e->getExitCode());
        }
    }

    #[Test]
    public function activeLockYieldsLockCode(): void
    {
        $lock = $this->get(ImportLockService::class)->acquire();
        try {
            $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);
            self::fail('Erwartete LockException');
        } catch (LockException $e) {
            self::assertSame(ExitCode::LOCK_ACTIVE, $e->getExitCode());
        } finally {
            $this->get(ImportLockService::class)->release($lock);
        }
    }

    #[Test]
    public function tamperedFileYieldsIntegrityCode(): void
    {
        $data = json_decode((string)file_get_contents($this->exportFile), true);
        // Datenblock nach dem Export verändern -> Prüfsumme passt nicht mehr.
        $data['pages'][0]['title'] = 'MANIPULIERT';
        file_put_contents($this->exportFile, (string)json_encode($data));

        try {
            $this->get(ImportService::class)->runImport($this->exportFile, 0, ['workspaceId' => 0]);
            self::fail('Erwartete IntegrityException');
        } catch (IntegrityException $e) {
            self::assertSame(ExitCode::INTEGRITY, $e->getExitCode());
        }
    }

    #[Test]
    public function conflictWithAbortYieldsConflictCode(): void
    {
        $import = $this->get(ImportService::class);
        $import->runImport($this->exportFile, 0, ['workspaceId' => 0]);

        // Eine importierte Zielseite lokal inhaltlich ändern und in die Zukunft datieren
        // -> beim erneuten Delta-Import ist sie nicht identisch und gilt als Konflikt.
        $newUid = (int)$this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_impexpnl_uid_map')
            ->select('target_uid')->from('tx_impexpnl_uid_map')
            ->where("table_name = 'pages'", 'source_uid = 2')
            ->executeQuery()->fetchOne();
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')
            ->update('pages', ['title' => 'LOKAL ANDERS', 'tstamp' => time() + 100000], ['uid' => $newUid]);

        try {
            $import->runImport($this->exportFile, 0, ['workspaceId' => 0, 'deltaMode' => true, 'conflict' => 'abort']);
            self::fail('Erwartete ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(ExitCode::CONFLICTS, $e->getExitCode());
        }
    }

    #[Test]
    public function importCommandMapsExceptionToExitCode(): void
    {
        // Datei manipulieren -> Integritätsfehler -> Command muss Exit-Code 4 liefern.
        $data = json_decode((string)file_get_contents($this->exportFile), true);
        $data['pages'][0]['title'] = 'MANIPULIERT';
        file_put_contents($this->exportFile, (string)json_encode($data));

        $tester = new CommandTester($this->get(ImportCommand::class));
        $exitCode = $tester->execute([
            'file' => $this->exportFile,
            'targetPid' => 0,
            '--json' => true,
        ]);

        self::assertSame(ExitCode::INTEGRITY, $exitCode, $tester->getDisplay());
        $result = json_decode($tester->getDisplay(), true);
        self::assertFalse($result['success']);
        self::assertSame(ExitCode::INTEGRITY, $result['exitCode']);
    }
}
