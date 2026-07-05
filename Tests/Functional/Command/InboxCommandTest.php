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

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Command\InboxCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * impexpnl:inbox listet eingegangene Pakete (read-only) und löst keinen Import aus.
 */
class InboxCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function listsPackagesAsJsonWithoutImporting(): void
    {
        $inbox = $this->instancePath . '/var/inbox';
        GeneralUtility::mkdir_deep($inbox);
        file_put_contents($inbox . '/paket_a.tar.gz', 'dummy');
        file_put_contents($inbox . '/paket_b.tar.gz', 'dummy2');
        file_put_contents($inbox . '/ignore.txt', 'nope');

        $tester = new CommandTester($this->get(InboxCommand::class));
        $exitCode = $tester->execute(['path' => $inbox, '--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $result = json_decode($tester->getDisplay(), true);
        self::assertTrue($result['success']);
        $names = array_column($result['packages'], 'name');
        self::assertContains('paket_a.tar.gz', $names);
        self::assertContains('paket_b.tar.gz', $names);
        self::assertNotContains('ignore.txt', $names, 'Nicht-Pakete dürfen nicht gelistet werden');
    }

    #[Test]
    public function missingDirectoryFailsClearly(): void
    {
        $tester = new CommandTester($this->get(InboxCommand::class));
        $exitCode = $tester->execute(['path' => $this->instancePath . '/var/gibt_es_nicht', '--json' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse((json_decode($tester->getDisplay(), true))['success']);
    }
}
