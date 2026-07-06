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
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Baut aus den exportierten FAL-Referenzen ein gehärtetes Asset-Manifest:
 * pro *eindeutiger* Datei `storage`, `identifier`, `size` und `sha256`.
 *
 * Das Manifest ist die Grundlage für inkrementellen Transfer (unveränderte Dateien
 * überspringen), Integritätsprüfung (gleicher Identifier / anderer Inhalt → Warnung)
 * und Asset-Preflight (Datei auf dem Zielsystem vorhanden?).
 */
class AssetManifestService
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<int, array<string, mixed>> $fileReferences Refs mit 'storage' und 'identifier'
     * @return list<array{storage:int, identifier:string, size:int, sha256:string|null}>
     */
    public function build(array $fileReferences): array
    {
        $seen = [];
        $assets = [];
        foreach ($fileReferences as $ref) {
            $storage = (int)($ref['storage'] ?? 0);
            $identifier = (string)($ref['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $key = $storage . ':' . $identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            [$size, $sha256] = $this->fileMeta($storage, $identifier);
            $assets[] = [
                'storage' => $storage,
                'identifier' => $identifier,
                'size' => $size,
                'sha256' => $sha256,
            ];
        }

        // Stabile Reihenfolge (reproduzierbarer Export / diffbares Manifest).
        usort($assets, static fn(array $a, array $b): int => [$a['storage'], $a['identifier']] <=> [$b['storage'], $b['identifier']]);

        return $assets;
    }

    /**
     * @return array{0:int, 1:string|null} [size, sha256] – sha256 null, wenn die Datei
     *                                      nicht lesbar/auffindbar ist (Export bricht nicht ab).
     */
    private function fileMeta(int $storage, string $identifier): array
    {
        try {
            $storageObject = $this->storageRepository->getStorageObject($storage);
            if (!$storageObject->hasFile($identifier)) {
                $this->logger->warning('Asset manifest: file not found', ['storage' => $storage, 'identifier' => $identifier]);
                return [0, null];
            }
            $file = $storageObject->getFile($identifier);
            $size = $file instanceof File ? (int)$file->getSize() : 0;

            return [$size, $this->sha256Of($file)];
        } catch (\Throwable $e) {
            $this->logger->warning('Asset manifest: metadata could not be determined', [
                'storage' => $storage,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return [0, null];
        }
    }

    /**
     * Vergleicht das Manifest gegen die Dateien auf dem (Ziel-)System: meldet
     * fehlende Dateien (`missing`) und – Kern der Integritätsprüfung – Dateien mit
     * gleichem Identifier, aber anderem Inhalt (`mismatch`). Reine Lese-Operation.
     *
     * @param array<int, array{storage?:int, identifier?:string, sha256?:string|null}> $assets
     * @return list<array{storage:int, identifier:string, reason:string, expected:string|null, actual:string|null}>
     */
    public function verifyAgainstTarget(array $assets): array
    {
        $issues = [];
        foreach ($assets as $asset) {
            $storage = (int)($asset['storage'] ?? 0);
            $identifier = (string)($asset['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $expected = isset($asset['sha256']) ? (string)$asset['sha256'] : null;

            try {
                $storageObject = $this->storageRepository->getStorageObject($storage);
                if (!$storageObject->hasFile($identifier)) {
                    $issues[] = ['storage' => $storage, 'identifier' => $identifier, 'reason' => 'missing', 'expected' => $expected, 'actual' => null];
                    continue;
                }
                if ($expected === null) {
                    // Beim Export kein Hash ermittelbar gewesen -> nichts zu vergleichen.
                    continue;
                }
                $actual = $this->sha256Of($storageObject->getFile($identifier));
                if ($actual !== null && !hash_equals($expected, $actual)) {
                    $issues[] = ['storage' => $storage, 'identifier' => $identifier, 'reason' => 'mismatch', 'expected' => $expected, 'actual' => $actual];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Asset integrity check failed', [
                    'storage' => $storage,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $issues;
    }

    /**
     * sha256 über den Dateiinhalt (lokaler Pfad, streamend); null bei Nicht-Lesbarkeit.
     */
    private function sha256Of(FileInterface $file): ?string
    {
        $localPath = $file->getForLocalProcessing(false);
        if (!is_file($localPath)) {
            return null;
        }
        $hash = hash_file('sha256', $localPath);
        return $hash !== false ? $hash : null;
    }
}
