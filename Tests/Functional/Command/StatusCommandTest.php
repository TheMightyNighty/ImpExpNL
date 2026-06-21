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
use Robbi\ImpExpNL\Command\StatusCommand;
use Robbi\ImpExpNL\Service\ImportLockService;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * impexpnl:status spiegelt den Betriebszustand: aktiver Import-Lock und letzter Import.
 */
class StatusCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function statusReportsNoLockByDefault(): void
    {
        $tester = new CommandTester($this->get(StatusCommand::class));
        $tester->execute(['--json' => true]);

        $status = json_decode($tester->getDisplay(), true);
        self::assertIsArray($status);
        self::assertFalse($status['lock']['active'], 'Ohne laufenden Import darf kein Lock aktiv sein');
    }

    #[Test]
    public function statusReflectsActiveLock(): void
    {
        $lockService = $this->get(ImportLockService::class);
        $lock = $lockService->acquire();
        try {
            $tester = new CommandTester($this->get(StatusCommand::class));
            $tester->execute(['--json' => true]);

            $status = json_decode($tester->getDisplay(), true);
            self::assertTrue($status['lock']['active'], 'Aktiver Import-Lock wird im Status nicht angezeigt');
            self::assertFalse($status['lock']['stale'], 'Frischer Lock darf nicht als veraltet gelten');
        } finally {
            $lockService->release($lock);
        }
    }
}
