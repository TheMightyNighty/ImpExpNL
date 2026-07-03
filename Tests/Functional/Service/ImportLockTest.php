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
 * Concurrency-Lock: Owner-Token-Bindung von Heartbeat und Freigabe.
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
        self::assertNotNull($this->lock()->getActiveLock());

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

        self::assertNull($this->lock()->getActiveLock());
        self::assertSame(0, $this->rowCount());
    }

    #[Test]
    public function releaseDoesNotRemoveForeignLock(): void
    {
        // Eigener Lock, dessen Zeile aber zwischenzeitlich von einem ANDEREN
        // Prozess übernommen wurde (Stale-Reap + Neu-Erwerb): release() darf
        // den fremden Lock nicht löschen.
        $lock = $this->lock()->acquire();
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['owner' => 'fremdes-token'], ['lock_id' => 'import']);

        $this->lock()->release($lock);

        self::assertSame(1, $this->rowCount(), 'Fremde Lock-Zeile muss nach release erhalten bleiben');
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['lock_id' => 'import']);
    }

    #[Test]
    public function refreshThrowsWhenOwnLockRowIsGone(): void
    {
        $lock = $this->lock()->acquire();
        // Simulierter Stale-Reap durch einen anderen Knoten.
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['lock_id' => 'import']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->lock()->refresh();
        } finally {
            $this->lock()->release($lock);
        }
    }

    #[Test]
    public function forceReleaseRemovesForeignLock(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'lock_id' => 'import',
            'owner' => 'fremdes-token',
            'info' => '{}',
            'created' => time(),
        ]);

        self::assertTrue($this->lock()->forceReleaseDbLock());
        self::assertSame(0, $this->rowCount());
    }
}
