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
use Robbi\ImpExpNL\Service\ImportLockService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Concurrency-Lock: ein zweiter Import wird abgewiesen, ein veralteter Lock wird
 * automatisch geerntet, und der Ops-Eingriff (unlock) löst den Lock wieder.
 */
class ImportLockTest extends FunctionalTestCase
{
    private const TABLE = 'tx_impexpnl_lock';

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private function lock(): ImportLockService
    {
        return $this->get(ImportLockService::class);
    }

    private function rowCount(): int
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->count('lock_id')->from(self::TABLE)->executeQuery()->fetchOne();
    }

    #[Test]
    public function secondAcquireIsRejectedWhileLockHeld(): void
    {
        $first = $this->lock()->acquire();
        self::assertNotNull($this->lock()->getActiveLock(), 'Lock sollte nach acquire aktiv sein');

        try {
            $this->expectException(\RuntimeException::class);
            $this->lock()->acquire();
        } finally {
            $this->lock()->release($first);
        }
    }

    #[Test]
    public function releaseFreesTheLock(): void
    {
        $lock = $this->lock()->acquire();
        $this->lock()->release($lock);

        self::assertNull($this->lock()->getActiveLock(), 'Lock muss nach release weg sein');
        self::assertSame(0, $this->rowCount(), 'Lock-Zeile muss nach release entfernt sein');
    }

    #[Test]
    public function staleLockIsReapedOnAcquire(): void
    {
        // Manuell einen uralten Lock setzen (älter als getLockStaleSeconds-Default 3600s).
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'lock_id' => 'import',
            'info' => '{"pid":1,"host":"ghost","started":"old"}',
            'created' => time() - 7200,
        ]);

        $active = $this->lock()->getActiveLock();
        self::assertNotNull($active);
        self::assertTrue($active['stale'], 'Alter Lock muss als veraltet erkannt werden');

        // acquire muss den veralteten Lock ernten und selbst übernehmen.
        $lock = $this->lock()->acquire();
        self::assertSame(1, $this->rowCount(), 'Es darf nur der neue Lock existieren');
        $fresh = $this->lock()->getActiveLock();
        self::assertNotNull($fresh);
        self::assertFalse($fresh['stale'], 'Neuer Lock darf nicht veraltet sein');

        $this->lock()->release($lock);
    }

    #[Test]
    public function getActiveLockReturnsNullWithoutLock(): void
    {
        self::assertNull($this->lock()->getActiveLock());
    }

    #[Test]
    public function forceReleaseReportsWhetherLockExisted(): void
    {
        self::assertFalse($this->lock()->forceReleaseDbLock(), 'Ohne Lock darf force-release false liefern');

        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'lock_id' => 'import',
            'info' => '{}',
            'created' => time(),
        ]);

        self::assertTrue($this->lock()->forceReleaseDbLock(), 'Mit Lock muss force-release true liefern');
        self::assertSame(0, $this->rowCount(), 'force-release muss die Lock-Zeile entfernen');
    }
}
