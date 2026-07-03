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

namespace Robbi\ImpExpNL\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Verhindert, dass der DataHandler während Import/Rollback die
 * Batch-Transaktionen zerstört.
 *
 * Hintergrund: Beim Anlegen/Löschen von Seiten flusht der TYPO3-Kern den
 * Rootline-Cache. Mit dem DB-Cache-Backend bedeutet das ein
 * `TRUNCATE cache_rootline` pro Record – und TRUNCATE ist in MySQL/MariaDB
 * ein implizites COMMIT: Die umgebende Batch-Transaktion wird beendet, alle
 * weiteren Statements laufen im Autocommit. Damit verlieren Batch und
 * Checkpoint ihre Atomarität, und die vielen TRUNCATE-Statements kosten
 * erheblich Durchsatz.
 *
 * defer() registriert daher – NUR für diesen CLI-Prozess – einen
 * In-Memory-Rootline-Cache, bevor der echte instanziert wird. flush() leert
 * die DB-Cache-Tabellen anschließend EINMAL außerhalb der Transaktionen,
 * damit andere Prozesse keine veralteten Rootlines lesen.
 *
 * Greift ausschließlich, wenn der Rootline-Cache auf dem DB-Backend liegt
 * (Redis & Co. kennen kein TRUNCATE-Problem); schlägt die Registrierung fehl
 * (Cache bereits instanziert), bleibt alles beim heutigen Verhalten.
 */
class RootlineCacheGuard
{
    private bool $deferred = false;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger
    ) {}

    public function defer(): void
    {
        if ($this->deferred) {
            return;
        }
        $conf = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['rootline'] ?? [];
        $backendClass = (string)($conf['backend'] ?? Typo3DatabaseBackend::class);
        if (!is_a($backendClass, Typo3DatabaseBackend::class, true)) {
            return;
        }
        try {
            $backend = new TransientMemoryBackend([]);
            $frontend = new VariableFrontend('rootline', $backend);
            $backend->setCache($frontend);
            $groups = (array)($conf['groups'] ?? ['pages']);
            GeneralUtility::makeInstance(CacheManager::class)->registerCache($frontend, $groups);
            $this->deferred = true;
        } catch (\Throwable $e) {
            // Cache bereits instanziert o. Ä. → unverändertes Kernverhalten
            // (TRUNCATE pro Record); nur informieren.
            $this->logger->info('Rootline-Cache konnte nicht umgelenkt werden – Batch-Transaktionen ggf. nicht atomar: ' . $e->getMessage());
        }
    }

    /**
     * Nachgelagerte Invalidierung: leert die DB-Rootline-Cache-Tabellen einmal,
     * NACH den Transaktionen (das implizite COMMIT des TRUNCATE ist hier harmlos).
     */
    public function flush(): void
    {
        if (!$this->deferred) {
            return;
        }
        $this->deferred = false;
        foreach (['cache_rootline', 'cache_rootline_tags'] as $table) {
            try {
                $this->connectionPool->getConnectionByName('Default')->truncate($table);
            } catch (\Throwable $e) {
                $this->logger->warning("Rootline-Cache-Tabelle $table konnte nicht geleert werden: " . $e->getMessage());
            }
        }
    }
}
