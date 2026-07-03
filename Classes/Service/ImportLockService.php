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
    private const TABLE = 'tx_impexpnl_lock';

    private bool $dbLockHeld = false;
    private bool $shutdownRegistered = false;

    /**
     * Zufälliges Inhaber-Token dieses Prozesses. Refresh/Release wirken nur auf
     * die eigene Lock-Zeile (WHERE owner = token) – hat der Stale-Reaper eines
     * anderen Knotens unseren Lock entfernt und ein Dritter neu erworben, wird
     * dessen Lock weder aufgefrischt noch gelöscht.
     */
    private string $ownerToken = '';

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

        $lockFile = Environment::getVarPath() . '/impexpnl_import.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            // Der DB-Lock trägt die Concurrency-Garantie allein weiter – aber ein
            // nicht anlegbarer Datei-Lock (z. B. read-only Filesystem im Container)
            // darf nicht unbemerkt bleiben.
            $this->logger->warning('Datei-Lock konnte nicht angelegt werden – nur DB-Lock aktiv: ' . $lockFile);
        }
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
     *
     * @throws \RuntimeException wenn die eigene Lock-Zeile nicht mehr existiert
     *         (Stale-Timeout oder manuelles unlock) – der laufende Import muss
     *         dann abbrechen, statt parallel zu einem fremden Import zu schreiben.
     */
    public function refresh(): void
    {
        if (!$this->dbLockHeld) {
            return;
        }
        try {
            $affected = $this->connectionPool->getConnectionForTable(self::TABLE)
                ->update(self::TABLE, ['created' => time()], ['lock_id' => self::LOCK_ID, 'owner' => $this->ownerToken]);

            // MySQL/MariaDB melden 0 "affected rows", wenn die Zeile zwar matcht,
            // sich aber kein Wert ändert (zwei Heartbeats in derselben Sekunde).
            // Lock-Verlust daher nur nach explizitem Nachweis annehmen, dass die
            // eigene Zeile wirklich fehlt.
            if ((int)$affected === 0 && $this->ownLockRowExists()) {
                return;
            }
        } catch (\Throwable $e) {
            // Transienter DB-Fehler: nicht abbrechen, nächster Heartbeat versucht es erneut.
            $this->logger->warning('DB-Lock-Heartbeat fehlgeschlagen: ' . $e->getMessage());
            return;
        }
        if ((int)$affected === 0) {
            $this->dbLockHeld = false;
            throw new \RuntimeException(
                'Import-Lock verloren: Die eigene Lock-Zeile existiert nicht mehr (Stale-Timeout oder '
                . 'manuelles unlock). Import wird abgebrochen, um nicht parallel zu einem anderen Import zu schreiben.'
            );
        }
    }

    private function ownLockRowExists(): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (bool)$qb->count('lock_id')->from(self::TABLE)
            ->where(
                $qb->expr()->eq('lock_id', $qb->createNamedParameter(self::LOCK_ID)),
                $qb->expr()->eq('owner', $qb->createNamedParameter($this->ownerToken))
            )
            ->executeQuery()->fetchOne();
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

        $this->ownerToken = bin2hex(random_bytes(16));
        try {
            $conn->insert(self::TABLE, [
                'lock_id' => self::LOCK_ID,
                'owner' => $this->ownerToken,
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
            // Nur die EIGENE Lock-Zeile löschen – niemals den Lock eines anderen
            // Prozesses, der nach einem Stale-Reap neu erworben wurde.
            $this->connectionPool->getConnectionForTable(self::TABLE)
                ->delete(self::TABLE, ['lock_id' => self::LOCK_ID, 'owner' => $this->ownerToken]);
        } catch (\Throwable $e) {
            $this->logger->warning('DB-Lock konnte nicht freigegeben werden: ' . $e->getMessage());
        }
    }

    /**
     * Liefert den aktiven DB-Lock oder null. Für Status/Diagnose.
     *
     * @return array{info: array<string, mixed>, created: int, age: int, stale: bool}|null
     */
    public function getActiveLock(): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('info', 'created')->from(self::TABLE)
            ->where($qb->expr()->eq('lock_id', $qb->createNamedParameter(self::LOCK_ID)))
            ->executeQuery()->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $created = (int)$row['created'];
        $info = json_decode((string)$row['info'], true);
        return [
            'info' => is_array($info) ? $info : [],
            'created' => $created,
            'age' => time() - $created,
            'stale' => (time() - $created) > $this->configurationService->getLockStaleSeconds(),
        ];
    }

    /**
     * Löst den Lock manuell (Ops-Eingriff bei hängendem Lock) – DB-Lock UND Datei-Lock.
     *
     * Nach einem harten Crash (SIGKILL/OOM) bleibt die Datei var/impexpnl_import.lock liegen
     * und blockiert den nächsten acquire, obwohl kein Import mehr läuft. Da unlock ein
     * expliziter Operator-Eingriff ist, wird die Lock-Datei mit entfernt.
     *
     * @return bool true, wenn ein Lock (DB oder Datei) vorhanden war
     */
    public function forceReleaseDbLock(): bool
    {
        $existed = $this->getActiveLock() !== null;
        $this->dbLockHeld = false;
        try {
            // Ops-Eingriff: löscht ohne Owner-Bedingung (auch fremde/hängende Locks).
            $this->connectionPool->getConnectionForTable(self::TABLE)
                ->delete(self::TABLE, ['lock_id' => self::LOCK_ID]);
        } catch (\Throwable $e) {
            $this->logger->warning('DB-Lock konnte nicht freigegeben werden: ' . $e->getMessage());
        }

        $lockFile = Environment::getVarPath() . '/impexpnl_import.lock';
        if (is_file($lockFile)) {
            $existed = true;
            @unlink($lockFile);
        }
        return $existed;
    }

    /**
     * Existiert (noch) die Datei-Lock-Datei? Kann nach einem harten Crash verwaist
     * zurückbleiben, während der DB-Lock bereits vom Stale-Reaper entfernt wurde.
     */
    public function hasFileLock(): bool
    {
        return is_file(Environment::getVarPath() . '/impexpnl_import.lock');
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
