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
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function isBundle(string $path): bool
    {
        return preg_match('/\.(tar\.gz|tgz|tar)$/i', $path) === 1;
    }

    /**
     * Entpackt das Bundle, platziert (außer im Dry-Run) die Asset-Dateien im Ziel
     * und gibt den Pfad zur enthaltenen Export-Datei (JSON/JSONL) zurück.
     */
    public function extract(string $bundlePath, bool $placeAssets = true): string
    {
        if (!is_file($bundlePath)) {
            throw new \RuntimeException("Bundle nicht gefunden: $bundlePath");
        }

        $targetDir = Environment::getVarPath() . '/transient/impexpnl_bundle_' . bin2hex(random_bytes(6));
        GeneralUtility::mkdir_deep($targetDir);

        // Primärpfad: natives `tar -xzf` (O(n) streamend, kein Phar-Prozess-Cache-Problem,
        // flacher Speicher). Fallback auf PharData, wenn proc_open/tar nicht verfügbar sind.
        if (!$this->extractWithTar($bundlePath, $targetDir)) {
            $this->extractWithPharData($bundlePath, $targetDir);
        }

        $exportFile = $this->findExportFile($targetDir);
        if ($exportFile === null) {
            throw new \RuntimeException('Bundle enthält keine Export-Datei (JSON/JSONL).');
        }

        $placed = 0;
        if ($placeAssets) {
            foreach ($this->readAssetList($targetDir, $exportFile) as $asset) {
                if ($this->placeAsset($targetDir, (int)($asset['storage'] ?? 0), (string)($asset['identifier'] ?? ''))) {
                    $placed++;
                }
            }
        }
        $this->logger->info('Bundle entpackt', ['export' => basename($exportFile), 'assetsPlaced' => $placed]);

        return $exportFile;
    }

    /**
     * Liefert die zu platzierenden Assets. Bevorzugt das mitgepackte Manifest
     * `impexpnl_assets.json` (format-unabhängig); nur als Fallback wird die
     * Export-Datei gelesen – und das ausschließlich für JSON (bei JSONL würde
     * ein json_decode der Gesamtdatei fehlschlagen und still 0 Assets liefern).
     *
     * @return list<array<string, mixed>>
     */
    private function readAssetList(string $dir, string $exportFile): array
    {
        $manifestFile = $dir . '/impexpnl_assets.json';
        if (is_file($manifestFile)) {
            $assets = json_decode((string)file_get_contents($manifestFile), true);
            if (is_array($assets)) {
                return $assets;
            }
        }

        if (str_ends_with(strtolower($exportFile), '.json')) {
            $manifest = json_decode((string)file_get_contents($exportFile), true);
            if (is_array($manifest) && is_array($manifest['assets'] ?? null)) {
                return $manifest['assets'];
            }
        }

        $this->logger->warning('Bundle: kein Asset-Manifest gefunden, keine Dateien platziert.', ['export' => basename($exportFile)]);
        return [];
    }

    /**
     * Entfernt das transiente Entpack-Verzeichnis nach dem Import (Erfolg wie Abbruch).
     * Sicherheits-Guard: nur Pfade unterhalb von var/transient mit dem erwarteten
     * Präfix werden gelöscht – niemals fileadmin o. Ä.
     */
    public function cleanupTransient(string $dir): void
    {
        $transientBase = Environment::getVarPath() . '/transient/';
        $real = realpath($dir);
        if ($real === false
            || !str_starts_with($real, realpath($transientBase) ?: $transientBase)
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
            ['tar', '-xzf', $bundlePath, '-C', $targetDir],
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
            $this->logger->warning('Bundle: natives tar (extract) fehlgeschlagen – Fallback auf PharData.', [
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
            throw new \RuntimeException("Bundle nicht lesbar: $bundlePath");
        }
        try {
            $phar = new \PharData($workCopy);
            $phar->extractTo($targetDir, null, true);
        } finally {
            unset($phar);
            @unlink($workCopy);
        }
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
     * Legt eine extrahierte Asset-Datei in den Ziel-Storage, falls dort noch nicht vorhanden.
     */
    private function placeAsset(string $bundleDir, int $storage, string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        $localFile = $bundleDir . '/files/' . ltrim($identifier, '/');
        if (!is_file($localFile)) {
            return false;
        }
        try {
            $storageObject = $this->storageRepository->getStorageObject($storage);
            if ($storageObject->hasFile($identifier)) {
                return false;
            }
            $folderPath = trim(dirname($identifier), '/');
            $folder = ($folderPath === '' || $folderPath === '.')
                ? $storageObject->getRootLevelFolder()
                : ($storageObject->hasFolder($folderPath) ? $storageObject->getFolder($folderPath) : $storageObject->createFolder($folderPath));

            $storageObject->addFile($localFile, $folder, basename($identifier), DuplicationBehavior::REPLACE, false);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Bundle: Asset konnte nicht platziert werden', [
                'storage' => $storage,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
