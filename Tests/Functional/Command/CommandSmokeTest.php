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

namespace Robbi\ImpExpNL\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Command\CheckCommand;
use Robbi\ImpExpNL\Command\ExportCommand;
use Robbi\ImpExpNL\Command\ImportCommand;
use Robbi\ImpExpNL\Command\ListCommand;
use Robbi\ImpExpNL\Command\MigrateLegacySchemaCommand;
use Robbi\ImpExpNL\Command\StatusCommand;
use Robbi\ImpExpNL\Command\UndoCommand;
use Robbi\ImpExpNL\Command\UnlockCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Smoke-Test für die Console-Commands: prüft die #[AsCommand]-Verdrahtung und
 * die Constructor-Dependency-Injection (die auf TYPO3 v14 empfindliche Stelle)
 * sowie die tatsächliche Ausführung des Export-Commands.
 */
class CommandSmokeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function commandProvider(): array
    {
        return [
            'export' => [ExportCommand::class, 'impexpnl:export'],
            'import' => [ImportCommand::class, 'impexpnl:import'],
            'list' => [ListCommand::class, 'impexpnl:list'],
            'status' => [StatusCommand::class, 'impexpnl:status'],
            'check' => [CheckCommand::class, 'impexpnl:check'],
            'undo' => [UndoCommand::class, 'impexpnl:undo'],
            'unlock' => [UnlockCommand::class, 'impexpnl:unlock'],
            'migrate' => [MigrateLegacySchemaCommand::class, 'impexpnl:migrate-legacy-schema'],
        ];
    }

    #[Test]
    #[DataProvider('commandProvider')]
    public function commandIsWiredViaAttributeAndDependencyInjection(string $class, string $expectedName): void
    {
        $command = $this->get($class);

        self::assertInstanceOf(Command::class, $command);
        self::assertSame(
            $expectedName,
            $command->getName(),
            sprintf('%s ist nicht über #[AsCommand] registriert', $class)
        );
    }

    #[Test]
    public function exportCommandRunsAndWritesFile(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $tester = new CommandTester($this->get(ExportCommand::class));
        $exitCode = $tester->execute([
            'startPid' => 1,
            'outputFile' => 'var/cmd_export.json',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $result = json_decode($tester->getDisplay(), true);
        self::assertTrue($result['success'] ?? false, 'Export meldete keinen Erfolg');

        $exportFile = $this->instancePath . '/var/cmd_export.json';
        self::assertFileExists($exportFile, 'Export-Datei wurde nicht geschrieben');

        $data = json_decode((string)file_get_contents($exportFile), true);
        self::assertIsArray($data['pages'] ?? null);
        self::assertContains(1, array_column($data['pages'], 'uid'), 'Startseite fehlt im Export');
    }
}
