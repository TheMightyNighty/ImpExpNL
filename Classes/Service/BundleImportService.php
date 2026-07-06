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
use Robbi\ImpExpNL\Exception\IntegrityException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Gegenstück zu {@see \Robbi\ImpExpNL\Transport\BundleTransport}: nimmt ein beim
 * Export erzeugtes `.tar.gz`, entpackt es, legt die enthaltenen Asset-Dateien in die
 * Ziel-Storages (FAL indiziert sie beim späteren Import) und liefert den Pfad zur
 * enthaltenen Export-Datei zurück. Macht `bundle` zum echten Cross-Instance-Round-Trip.
 */
class BundleImportService
{
    /** @var list<int> sys_file-UIDs, die der letzte placeAssets()-Lauf NEU platziert hat. */
    private array $placedFileUids = [];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function isBundle(string $path): bool
    {
        return preg_match('/\.(tar\.gz|tgz|tar)$/i', $path) === 1;
    }

    /**
     * sys_file-UIDs, die beim letzten placeAssets() neu in den Ziel-Storage platziert wurden.
     * Basis für den referenz-geprüften Asset-GC beim Rollback/Abbruch.
     *
     * @return list<int>
     */
    public function getPlacedFileUids(): array
    {
        return $this->placedFileUids;
    }

    /**
     * Entpackt das Bundle und gibt den Pfad zur enthaltenen Export-Datei (JSON/JSONL)
     * zurück. Es werden dabei KEINE Asset-Dateien platziert – das geschieht erst nach
     * erfolgreicher Integritäts-/Signaturprüfung der Export-Datei über
     * {@see placeAssets()} mit dem signierten Asset-Manifest als einziger Quelle.
     */
    public function extract(string $bundlePath): string
    {
        if (!is_file($bundlePath)) {
            throw new \RuntimeException("Bundle not found: $bundlePath");
        }

        // Link-Member (Symlinks/Hardlinks) VOR dem Entpacken ablehnen: ein Archiv,
        // das erst einen Symlink und danach eine Datei DURCH diesen Symlink enthält,
        // könnte je nach tar-Implementierung schon beim Entpacken außerhalb des
        // Zielverzeichnisses schreiben – der nachgelagerte Check käme dann zu spät.
        $this->assertNoLinkMembersInArchive($bundlePath);

        $targetDir = Environment::getVarPath() . '/transient/impexpnl_bundle_' . bin2hex(random_bytes(6));
        GeneralUtility::mkdir_deep($targetDir);

        // Primärpfad: natives `tar -xzf` (O(n) streamend, kein Phar-Prozess-Cache-Problem,
        // flacher Speicher). Fallback auf PharData, wenn proc_open/tar nicht verfügbar sind.
        if (!$this->extractWithTar($bundlePath, $targetDir)) {
            $this->extractWithPharData($bundlePath, $targetDir);
        }

        // Zweite Verteidigungslinie nach dem Entpacken (deckt auch den PharData-
        // Fallback ab, wenn der tar-Vorab-Check nicht laufen konnte).
        $this->assertNoSymlinks($targetDir, $bundlePath);

        $exportFile = $this->findExportFile($targetDir);
        if ($exportFile === null) {
            throw new \RuntimeException('Bundle contains no export file (JSON/JSONL).');
        }

        $this->placedFileUids = [];
        $this->logger->info('Bundle extracted', ['export' => basename($exportFile)]);

        return $exportFile;
    }

    /**
     * Platziert Asset-Dateien aus dem entpackten Bundle in die Ziel-Storages.
     * Quelle ist ausschließlich das durch die Export-Signatur abgedeckte Asset-Manifest
     * (`assets`-Block der verifizierten Export-Datei) – nicht die unsignierte
     * `impexpnl_assets.json`. Jede Datei wird vor der Übernahme gegen ihre
     * signierte sha256-Prüfsumme verifiziert.
     *
     * @param array<int, mixed> $assets Einträge aus dem verifizierten Manifest
     * @param callable|null $onAssetsPlaced Wird während der Platzierung periodisch (und am
     *        Ende) mit der bisherigen Liste platzierter sys_file-UIDs aufgerufen – ermöglicht
     *        ein Checkpointing, sodass auch ein Crash WÄHREND der Platzierung rückrollbar bleibt.
     */
    public function placeAssets(string $bundleDir, array $assets, ?callable $onAssetsPlaced = null): void
    {
        $this->placedFileUids = [];
        $since = 0;
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $sha256 = $asset['sha256'] ?? null;
            $uid = $this->placeAsset(
                $bundleDir,
                (int)($asset['storage'] ?? 0),
                (string)($asset['identifier'] ?? ''),
                is_string($sha256) && $sha256 !== '' ? $sha256 : null
            );
            if ($uid !== null) {
                $this->placedFileUids[] = $uid;
            }
            // Periodischer Checkpoint (alle 200 Dateien), damit ein Crash mitten in der
            // Platzierung die bis dahin platzierten Dateien rückrollbar lässt.
            if ($onAssetsPlaced !== null && (++$since) % 200 === 0) {
                $onAssetsPlaced($this->placedFileUids);
            }
        }
        if ($onAssetsPlaced !== null && $this->placedFileUids !== []) {
            $onAssetsPlaced($this->placedFileUids);
        }
        $this->logger->info('Bundle assets placed', ['assetsPlaced' => count($this->placedFileUids)]);
    }

    /**
     * Entfernt das transiente Entpack-Verzeichnis nach dem Import (Erfolg wie Abbruch).
     * Sicherheits-Guard: nur Pfade unterhalb von var/transient mit dem erwarteten
     * Präfix werden gelöscht – niemals fileadmin o. Ä.
     */
    public function cleanupTransient(string $dir): void
    {
        $transientBase = Environment::getVarPath() . '/transient';
        $resolvedBase = rtrim(realpath($transientBase) ?: $transientBase, '/') . '/';
        $real = realpath($dir);
        if ($real === false
            || !str_starts_with($real, $resolvedBase)
            || !str_contains(basename($real), 'impexpnl_bundle_')
        ) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($real);
    }

    /**
     * Entpackt das Bundle mit nativem `tar -xzf`. true bei Erfolg, false wenn
     * proc_open/tar nicht verfügbar sind oder tar fehlschlägt (→ PharData-Fallback).
     */
    private function extractWithTar(string $bundlePath, string $targetDir): bool
    {
        if (!\function_exists('proc_open')) {
            return false;
        }
        $process = @proc_open(
            // --no-same-owner/--no-same-permissions: keine Owner-/Modus-Übernahme aus
            // dem (potenziell fremden) Archiv; Symlink-Member fängt assertNoSymlinks() ab.
            ['tar', '-xzf', $bundlePath, '-C', $targetDir, '--no-same-owner', '--no-same-permissions'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            return false;
        }
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->warning('Bundle: native tar (extract) failed – falling back to PharData.', [
                'exitCode' => $exitCode,
                'stderr' => trim((string)$stderr),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Fallback-Extraktion via PharData. PharData cacht Archive prozessweit pro Pfad;
     * eine eindeutig benannte Arbeitskopie umgeht inkonsistente Bytes, wenn im selben
     * Prozess erst gepackt und dann entpackt wird (Export→Import in einem Lauf).
     */
    private function extractWithPharData(string $bundlePath, string $targetDir): void
    {
        $workCopy = $targetDir . '.' . pathinfo($bundlePath, PATHINFO_BASENAME);
        if (!copy($bundlePath, $workCopy)) {
            throw new \RuntimeException("Bundle not readable: $bundlePath");
        }
        try {
            $phar = new \PharData($workCopy);
            $phar->extractTo($targetDir, null, true);
        } finally {
            unset($phar);
            @unlink($workCopy);
        }
    }

    /**
     * Prüft das Archiv-Inhaltsverzeichnis (`tar -tvf`) VOR dem Entpacken auf
     * Link-Member: Typ `l` (Symlink) und `h` (Hardlink) als erstes Zeichen der
     * Mode-Spalte. Solche Archive werden abgelehnt, statt erst nach dem Entpacken
     * aufzuräumen. Best-effort: ohne proc_open/tar läuft der Check nicht (dann
     * greift {@see assertNoSymlinks()} nach der Extraktion als zweite Linie).
     */
    private function assertNoLinkMembersInArchive(string $bundlePath): void
    {
        if (!\function_exists('proc_open')) {
            return;
        }
        // `-tvf` liest nur das Inhaltsverzeichnis (GNU tar erkennt gzip beim Lesen
        // automatisch); es wird nichts auf die Platte geschrieben.
        $process = @proc_open(
            ['tar', '-tvf', $bundlePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            return;
        }
        $offender = null;
        while (($line = fgets($pipes[1])) !== false) {
            $type = $line[0] ?? '';
            if ($type === 'l' || $type === 'h') {
                $offender = trim($line);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($offender !== null) {
            throw new IntegrityException(sprintf(
                'Bundle contains a link member (symlink/hardlink) and is rejected: %s [%s]',
                basename($bundlePath),
                $offender
            ));
        }
        if ($exitCode !== 0) {
            // Listing fehlgeschlagen (defektes/fremdes Archiv): nicht hier raten –
            // die Extraktion liefert die aussagekräftige Fehlermeldung.
            $this->logger->warning('Bundle: tar listing (pre-extraction link-member check) failed.', [
                'bundle' => basename($bundlePath),
                'exitCode' => $exitCode,
            ]);
        }
    }

    /**
     * Lehnt Bundles mit Symlink-Membern ab. Ein Symlink im Archiv könnte beim
     * Platzieren der Assets Dateien außerhalb des Entpack-Verzeichnisses lesen
     * (Exfiltration in den FAL-Storage) oder – je nach tar-Implementierung –
     * beim Entpacken außerhalb schreiben.
     */
    private function assertNoSymlinks(string $dir, string $bundlePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new IntegrityException(sprintf(
                    'Bundle contains a symlink (%s) – archive is rejected: %s',
                    $item->getPathname(),
                    basename($bundlePath)
                ));
            }
        }
    }

    /**
     * Erlaubt nur harmlose, relative Identifier ohne `..`-Segmente, Backslashes
     * oder NUL-Bytes (Path-Traversal-Schutz für das Asset-Manifest).
     */
    private function isSafeIdentifier(string $identifier): bool
    {
        if ($identifier === '' || str_contains($identifier, '\\') || str_contains($identifier, "\0")) {
            return false;
        }
        foreach (explode('/', $identifier) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function findExportFile(string $dir): ?string
    {
        foreach ((array)glob($dir . '/*.{json,jsonl}', GLOB_BRACE) as $file) {
            if (basename($file) !== 'impexpnl_assets.json') {
                return $file;
            }
        }
        return null;
    }

    /**
     * Legt eine extrahierte Asset-Datei in den Ziel-Storage. Liefert die UID des NEU
     * angelegten sys_file zurück (Basis für den Rollback-GC) bzw. null, wenn die Datei
     * bereits vorhanden war oder nicht platziert werden konnte.
     *
     * Sicherheits-Invarianten: Der Identifier darf keine `..`-Segmente enthalten, die
     * Quelldatei muss nach realpath-Auflösung unterhalb von `<bundle>/files/` liegen,
     * und ihr Inhalt muss der signierten sha256-Prüfsumme aus dem Manifest entsprechen.
     *
     * @throws IntegrityException wenn der Dateiinhalt von der signierten Prüfsumme abweicht
     */
    private function placeAsset(string $bundleDir, int $storage, string $identifier, ?string $expectedSha256): ?int
    {
        if (!$this->isSafeIdentifier($identifier)) {
            $this->logger->warning('Bundle: unsafe asset identifier skipped', ['identifier' => $identifier]);
            return null;
        }
        $filesBase = $bundleDir . '/files';
        $localFile = $filesBase . '/' . ltrim($identifier, '/');
        if (!is_file($localFile)) {
            return null;
        }
        // Containment nach Auflösung: Quelldatei muss unterhalb von <bundle>/files/ liegen.
        $real = realpath($localFile);
        $realBase = realpath($filesBase);
        if ($real === false || $realBase === false || !str_starts_with($real, $realBase . '/')) {
            $this->logger->warning('Bundle: asset path outside the bundle skipped', ['identifier' => $identifier]);
            return null;
        }
        if ($expectedSha256 === null) {
            $this->logger->warning('Bundle: asset without a signed checksum skipped', ['identifier' => $identifier]);
            return null;
        }
        $actual = hash_file('sha256', $real);
        if ($actual === false || !hash_equals($expectedSha256, $actual)) {
            throw new IntegrityException(sprintf(
                'Bundle asset differs from the signed checksum: %s (bundle tampered with or corrupted).',
                $identifier
            ));
        }
        $localFile = $real;
        try {
            $storageObject = $this->storageRepository->getStorageObject($storage);
            if ($storageObject->hasFile($identifier)) {
                return null;
            }
            $folderPath = trim(dirname($identifier), '/');
            $folder = ($folderPath === '' || $folderPath === '.')
                ? $storageObject->getRootLevelFolder()
                : ($storageObject->hasFolder($folderPath) ? $storageObject->getFolder($folderPath) : $storageObject->createFolder($folderPath));

            // Verschieben statt kopieren: die Quelle liegt im transienten Wegwerf-Verzeichnis
            // (gleiches Dateisystem → rename statt 391-MB-Copy).
            $file = $storageObject->addFile($localFile, $folder, basename($identifier), DuplicationBehavior::REPLACE, true);
            return (int)$file->getUid();
        } catch (\Throwable $e) {
            $this->logger->warning('Bundle: asset could not be placed', [
                'storage' => $storage,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
