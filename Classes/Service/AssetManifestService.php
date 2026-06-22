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
                $this->logger->warning('Asset-Manifest: Datei nicht gefunden', ['storage' => $storage, 'identifier' => $identifier]);
                return [0, null];
            }
            $file = $storageObject->getFile($identifier);
            $size = $file instanceof File ? (int)$file->getSize() : 0;

            $sha256 = null;
            $localPath = $file->getForLocalProcessing(false);
            if (is_file($localPath)) {
                $hash = hash_file('sha256', $localPath);
                $sha256 = $hash !== false ? $hash : null;
            }

            return [$size, $sha256];
        } catch (\Throwable $e) {
            $this->logger->warning('Asset-Manifest: Metadaten nicht ermittelbar', [
                'storage' => $storage,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return [0, null];
        }
    }
}
