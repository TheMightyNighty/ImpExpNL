<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Concurrency-Lock für Importe: ein cluster-weiter DB-Lock (wirkt über alle
 * Pods/Knoten, die sich die Datenbank teilen) plus ein lokaler Datei-Lock als
 * schneller Fast-Fail innerhalb eines Knotens.
 */
class ImportLockService
{
    private const LOCK_ID = 'import';
    private const TABLE = 'tx_robbicopy_lock';

    private bool $dbLockHeld = false;
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configurationService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array{db: bool, file: resource|null}
     */
    public function acquire(): array
    {
        $this->acquireDbLock();

        $lockFile = Environment::getVarPath() . '/robbicopy_import.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = fopen($lockFile, 'c+');
        if ($handle && !flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->releaseDbLock();
            throw new \RuntimeException('Ein anderer Import läuft (Datei-Lock): ' . $lockFile);
        }
        if ($handle) {
            ftruncate($handle, 0);
            fwrite($handle, (string)json_encode(['pid' => getmypid(), 'started' => date('c')]));
            fflush($handle);
        }

        return ['db' => true, 'file' => $handle ?: null];
    }

    /**
     * @param array{db?: bool, file?: resource|null} $lock
     */
    public function release(array $lock): void
    {
        $handle = $lock['file'] ?? null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!empty($lock['db'])) {
            $this->releaseDbLock();
        }
    }

    /**
     * Hält den DB-Lock bei lang laufenden Importen frisch, damit er nicht vom
     * Stale-Reaper eines anderen Prozesses entfernt wird.
     */
    public function refresh(): void
    {
        if (!$this->dbLockHeld) {
            return;
        }
        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)
                ->update(self::TABLE, ['created' => time()], ['lock_id' => self::LOCK_ID]);
        } catch (\Throwable $e) {
            $this->logger->warning('DB-Lock-Heartbeat fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function acquireDbLock(): void
    {
        $staleSeconds = $this->configurationService->getLockStaleSeconds();
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->eq('lock_id', $qb->createNamedParameter(self::LOCK_ID)),
                $qb->expr()->lt('created', $qb->createNamedParameter(time() - $staleSeconds, Connection::PARAM_INT))
            )
            ->executeStatement();

        try {
            $conn->insert(self::TABLE, [
                'lock_id' => self::LOCK_ID,
                'info' => substr((string)json_encode(['pid' => getmypid(), 'host' => gethostname() ?: '?', 'started' => date('c')]), 0, 255),
                'created' => time(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Ein anderer Import läuft bereits (DB-Lock aktiv). Bei einem Crash wird der Lock nach ' . $staleSeconds . 's automatisch freigegeben.');
        }

        $this->dbLockHeld = true;
        $this->registerShutdownRelease();
    }

    private function releaseDbLock(): void
    {
        $this->dbLockHeld = false;
        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)
                ->delete(self::TABLE, ['lock_id' => self::LOCK_ID]);
        } catch (\Throwable $e) {
            $this->logger->warning('DB-Lock konnte nicht freigegeben werden: ' . $e->getMessage());
        }
    }

    /**
     * Gibt den DB-Lock auch bei einem fatalen Fehler / Skriptende frei,
     * sofern er nicht zuvor regulär freigegeben wurde.
     */
    private function registerShutdownRelease(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            if ($this->dbLockHeld) {
                $this->releaseDbLock();
            }
        });
    }
}
