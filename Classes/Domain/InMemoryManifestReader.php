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

namespace Robbi\ImpExpNL\Domain;

/**
 * {@see ManifestReader} für den JSON-Pfad: der gesamte Datenblock liegt im Speicher
 * (dünne Hülle um {@see ExportManifest}). Verhalten wie vor der Streaming-Umstellung.
 */
final class InMemoryManifestReader implements ManifestReader
{
    public function __construct(private readonly ExportManifest $manifest) {}

    public function getMeta(): array
    {
        return $this->manifest->getMeta();
    }

    public function getSourceId(): string
    {
        return $this->manifest->getSourceId();
    }

    public function getChecksum(): ?string
    {
        return $this->manifest->getChecksum();
    }

    public function hasPages(): bool
    {
        return $this->manifest->hasPages();
    }

    public function getSiteConfig(): array
    {
        return $this->manifest->getSiteConfig();
    }

    public function getAssets(): array
    {
        return $this->manifest->getAssets();
    }

    public function getFileReferences(): array
    {
        return $this->manifest->getFileReferences();
    }

    public function getIrreRelations(): array
    {
        return $this->manifest->getIrreRelations();
    }

    public function eachRecord(string $table): iterable
    {
        return $this->manifest->toArray()[$table] ?? [];
    }

    public function collectUids(string $table): array
    {
        $uids = [];
        foreach ($this->manifest->toArray()[$table] ?? [] as $record) {
            if (isset($record['uid'])) {
                $uids[] = (int)$record['uid'];
            }
        }
        return $uids;
    }

    public function count(string $table): int
    {
        return count($this->manifest->toArray()[$table] ?? []);
    }

    public function getAuxiliaryData(): array
    {
        $data = $this->manifest->toArray();
        unset($data['pages'], $data['tt_content'], $data['_meta']);
        return $data;
    }
}
