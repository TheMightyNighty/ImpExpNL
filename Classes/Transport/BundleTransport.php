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

namespace Robbi\ImpExpNL\Transport;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Self-contained-Transport: packt Export-Datei, Asset-Manifest und – sofern
 * `includeAssets` (Default an) – die referenzierten Dateien selbst in ein
 * `.tar.gz`. Ergebnis ist EIN übertragbares Paket; auf dem Ziel auspacken und
 * importieren. (zstd ist als spätere Option vorgesehen; garantiert ist `tar.gz`.)
 */
class BundleTransport implements TransportInterface
{
    /**
     * Erlaubte Kompressor-Programme für `tar --use-compress-program`. Der Wert
     * kommt aus der (auch von Dritt-Extensions beisteuerbaren) YAML-Config und
     * bestimmt das auszuführende Programm – daher strikte Allowlist statt
     * freier Programmname. ('' = tar-Pfad überspringen → PharData-Fallback.)
     */
    private const COMPRESSOR_ALLOWLIST = ['gzip', 'pigz'];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function getType(): string
    {
        return 'bundle';
    }

    public function package(string $exportFile, array $assets, array $config): array
    {
        $includeAssets = (bool)($config['includeAssets'] ?? true);

        $base = preg_replace('/\.jsonl?$/', '', $exportFile) ?? $exportFile;
        $gzPath = $base . '.tar.gz';
        @unlink($gzPath);

        // Archiv-Layout zusammenstellen: archivinterner Pfad => lokaler Quellpfad.
        $entries = [basename($exportFile) => $exportFile];
        $assetsJson = dirname($exportFile) . '/impexpnl_assets.json';
        if (is_file($assetsJson)) {
            $entries['impexpnl_assets.json'] = $assetsJson;
        }

        // Inkrementelles Bundle: Dateien, die laut Baseline-Manifest (Asset-Manifest
        // des Ziels) dort bereits inhaltsgleich vorliegen, werden nicht eingepackt.
        // Fehlt eine übersprungene Datei auf dem Ziel doch, meldet der Import das
        // über den Asset-Preflight bzw. Exit-Code 7 – es entsteht kein stiller Verlust.
        $baseline = $this->loadBaseline((string)($config['baseline'] ?? ''));

        $bundled = 0;
        $missing = 0;
        $skippedBaseline = 0;
        if ($includeAssets) {
            foreach ($assets as $asset) {
                $identifier = (string)($asset['identifier'] ?? '');
                $sha256 = (string)($asset['sha256'] ?? '');
                $baselineKey = (int)($asset['storage'] ?? 0) . ':' . $identifier;
                if ($sha256 !== '' && ($baseline[$baselineKey] ?? null) === $sha256) {
                    $skippedBaseline++;
                    continue;
                }
                $path = $this->localPath((int)($asset['storage'] ?? 0), $identifier);
                if ($path === null) {
                    $missing++;
                    continue;
                }
                $entries['files/' . ltrim($identifier, '/')] = $path;
                $bundled++;
            }
        }

        // Primärpfad: natives `tar` (ein streamender O(n)-Durchlauf inkl. Kompression).
        // PharData::addFile schreibt das Tar bei jedem Eintrag neu (O(n²)) und Phar::compress
        // ist single-threaded – beides für große Asset-Mengen praxisuntauglich. PharData bleibt
        // nur Fallback, wenn proc_open/tar nicht verfügbar sind (Hosting-Portabilität).
        $compressor = (string)($config['compressor'] ?? 'gzip');
        if ($compressor !== '' && !in_array($compressor, self::COMPRESSOR_ALLOWLIST, true)) {
            $this->logger->warning('Bundle: Kompressor nicht in der Allowlist – nutze gzip.', [
                'requested' => $compressor,
                'allowed' => self::COMPRESSOR_ALLOWLIST,
            ]);
            $compressor = 'gzip';
        }
        $mode = $this->packWithTar($entries, $gzPath, $compressor, dirname($base));
        if ($mode === null) {
            $this->packWithPharData($entries, $gzPath);
            $mode = 'php-gz';
        }

        // Wie die übrigen Export-Artefakte: Besitzer-Rechte statt Prozess-umask.
        if (is_file($gzPath) && !@chmod($gzPath, 0600)) {
            $this->logger->warning('Dateirechte konnten nicht beschränkt werden: ' . $gzPath);
        }

        $message = sprintf('Bundle geschrieben → %s', basename($gzPath));
        if ($includeAssets) {
            $details = [sprintf('%d Asset-Datei(en) enthalten', $bundled)];
            if ($skippedBaseline > 0) {
                $details[] = sprintf('%d laut Baseline bereits auf dem Ziel', $skippedBaseline);
            }
            if ($missing > 0) {
                $details[] = sprintf('%d fehlend übersprungen', $missing);
            }
            $message .= ' (' . implode(', ', $details) . ').';
        } else {
            $message .= ' (ohne Asset-Dateien).';
        }
        $message .= sprintf(' [%s]', $mode);
        if ($missing > 0) {
            $this->logger->warning(sprintf('Bundle: %d Asset-Datei(en) nicht auffindbar und ausgelassen.', $missing));
        }

        return ['type' => 'bundle', 'artifact' => $gzPath, 'message' => $message];
    }

    /**
     * Packt die Einträge via nativem `tar` über einen Symlink-Staging-Baum direkt nach
     * $gzPath (`tar --use-compress-program=<compressor> -chf …`). `-h` folgt den Symlinks,
     * sodass keine 391-MB-Kopie nötig ist. Gibt den Kompressor-Namen bei Erfolg zurück,
     * null bei fehlendem proc_open/tar oder Fehler (→ Aufrufer nutzt PharData-Fallback).
     *
     * @param array<string, string> $entries archivinterner Pfad => lokaler Quellpfad
     */
    private function packWithTar(array $entries, string $gzPath, string $compressor, string $workBase): ?string
    {
        if ($compressor === '' || !\function_exists('proc_open')) {
            return null;
        }
        $stage = $workBase . '/.impexpnl_bundle_' . bin2hex(random_bytes(6));
        if (!@mkdir($stage, 0775, true)) {
            return null;
        }
        try {
            foreach ($entries as $archiveName => $src) {
                $dest = $stage . '/' . $archiveName;
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                    return null;
                }
                // Symlink (kein Kopieren); Fallback Hardlink/Copy, falls Symlinks gesperrt.
                if (!@symlink($src, $dest) && !@link($src, $dest) && !@copy($src, $dest)) {
                    return null;
                }
            }

            // Top-Level-Einträge explizit übergeben (statt '.'), damit die Archiv-Pfade
            // KEIN './'-Präfix bekommen – so bleibt das Layout identisch zum bisherigen
            // PharData-Format (export.json, impexpnl_assets.json, files/…).
            $topLevel = [];
            foreach (array_keys($entries) as $archiveName) {
                $topLevel[explode('/', $archiveName)[0]] = true;
            }
            $cmd = array_merge(
                ['tar', '--use-compress-program=' . $compressor, '-chf', $gzPath, '-C', $stage],
                array_keys($topLevel)
            );
            $process = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                return null;
            }
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0 || !is_file($gzPath)) {
                $this->logger->warning('Bundle: natives tar fehlgeschlagen – Fallback auf PharData.', [
                    'compressor' => $compressor,
                    'exitCode' => $exitCode,
                    'stderr' => trim((string)$stderr),
                ]);
                @unlink($gzPath);
                return null;
            }
            return $compressor;
        } finally {
            $this->removeDir($stage);
        }
    }

    /**
     * Fallback ohne CLI: baut das Tar mit PharData und komprimiert mit Phar::compress.
     * Bewusst nur Notnagel (langsam bei vielen Dateien), damit restriktive Hosts ohne
     * proc_open/tar trotzdem ein gültiges .tar.gz erhalten.
     *
     * @param array<string, string> $entries archivinterner Pfad => lokaler Quellpfad
     */
    private function packWithPharData(array $entries, string $gzPath): void
    {
        $tarPath = preg_replace('/\.gz$/', '', $gzPath) ?? $gzPath;
        @unlink($tarPath);
        $tar = new \PharData($tarPath);
        foreach ($entries as $archiveName => $src) {
            $tar->addFile($src, $archiveName);
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        \Phar::unlinkArchive($tarPath);
    }

    /**
     * Entfernt den Staging-Baum. Es werden nur die Symlinks/Hilfsverzeichnisse gelöscht,
     * niemals die Symlink-Ziele (die echten Asset-Dateien).
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Lädt das Baseline-Manifest (impexpnl_assets.json des Ziels) als Map
     * "storage:identifier" => sha256. Ein unlesbares Manifest wird mit Warnung
     * ignoriert (Bundle wird dann vollständig gepackt – fail-safe).
     *
     * @return array<string, string>
     */
    private function loadBaseline(string $path): array
    {
        if ($path === '') {
            return [];
        }
        if (!is_file($path)) {
            $this->logger->warning('Bundle: Baseline-Manifest nicht gefunden – Bundle wird vollständig gepackt.', ['baseline' => $path]);
            return [];
        }
        $entries = json_decode((string)file_get_contents($path), true);
        if (!is_array($entries)) {
            $this->logger->warning('Bundle: Baseline-Manifest nicht lesbar (kein JSON-Array) – Bundle wird vollständig gepackt.', ['baseline' => $path]);
            return [];
        }
        $map = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $identifier = (string)($entry['identifier'] ?? '');
            $sha256 = (string)($entry['sha256'] ?? '');
            if ($identifier === '' || $sha256 === '') {
                continue;
            }
            $map[(int)($entry['storage'] ?? 0) . ':' . $identifier] = $sha256;
        }
        return $map;
    }

    private function localPath(int $storage, string $identifier): ?string
    {
        if ($identifier === '') {
            return null;
        }
        try {
            $storageObject = $this->storageRepository->getStorageObject($storage);
            if (!$storageObject->hasFile($identifier)) {
                return null;
            }
            $path = $storageObject->getFile($identifier)->getForLocalProcessing(false);
            return is_file($path) ? $path : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Bundle: Datei nicht lesbar', ['storage' => $storage, 'identifier' => $identifier, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
