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
        $tarPath = $base . '.tar';
        $gzPath = $tarPath . '.gz';
        @unlink($tarPath);
        @unlink($gzPath);

        $tar = new \PharData($tarPath);
        $tar->addFile($exportFile, basename($exportFile));

        $assetsJson = dirname($exportFile) . '/impexpnl_assets.json';
        if (is_file($assetsJson)) {
            $tar->addFile($assetsJson, 'impexpnl_assets.json');
        }

        $bundled = 0;
        $missing = 0;
        if ($includeAssets) {
            foreach ($assets as $asset) {
                $localName = 'files/' . ltrim((string)($asset['identifier'] ?? ''), '/');
                $path = $this->localPath((int)($asset['storage'] ?? 0), (string)($asset['identifier'] ?? ''));
                if ($path === null) {
                    $missing++;
                    continue;
                }
                $tar->addFile($path, $localName);
                $bundled++;
            }
        }

        $tar->compress(\Phar::GZ);
        // Handle freigeben, damit die unkomprimierte .tar entfernt werden kann.
        unset($tar);
        \Phar::unlinkArchive($tarPath);

        $message = sprintf('Bundle geschrieben → %s', basename($gzPath));
        if ($includeAssets) {
            $message .= sprintf(' (%d Asset-Datei(en) enthalten%s).', $bundled, $missing > 0 ? ", $missing fehlend übersprungen" : '');
        } else {
            $message .= ' (ohne Asset-Dateien).';
        }
        if ($missing > 0) {
            $this->logger->warning(sprintf('Bundle: %d Asset-Datei(en) nicht auffindbar und ausgelassen.', $missing));
        }

        return ['type' => 'bundle', 'artifact' => $gzPath, 'message' => $message];
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
