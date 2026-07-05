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

use Robbi\ImpExpNL\Service\JsonlStreamReader;

/**
 * {@see ManifestReader} für den JSONL-Pfad: pages/tt_content werden records-weise
 * gestreamt (konstanter Speicher), die kleineren relationalen Bereiche einmalig
 * materialisiert. Der `_meta`-Block wird beim Erzeugen einmal gelesen.
 */
final class StreamingManifestReader implements ManifestReader
{
    /** Tabellen, die gestreamt statt materialisiert werden. */
    private const STREAMED = ['pages', 'tt_content'];

    /** @var array<string, mixed>|null Lazy materialisierte Nicht-Stream-Tabellen. */
    private ?array $auxiliary = null;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly JsonlStreamReader $reader,
        private readonly string $path,
        private readonly array $meta
    ) {}

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getSourceId(): string
    {
        return (string)($this->meta['source_id'] ?? '');
    }

    public function getChecksum(): ?string
    {
        $checksum = $this->meta['checksum'] ?? null;
        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    public function hasPages(): bool
    {
        foreach ($this->reader->streamTable($this->path, 'pages') as $ignored) {
            return true;
        }
        return false;
    }

    public function getSiteConfig(): array
    {
        return $this->auxiliary()['_site_config'] ?? [];
    }

    public function getAssets(): array
    {
        return $this->auxiliary()['assets'] ?? [];
    }

    public function getFileReferences(): array
    {
        return $this->auxiliary()['sys_file_reference'] ?? [];
    }

    public function getIrreRelations(): array
    {
        return $this->auxiliary()['irre_relations'] ?? [];
    }

    public function eachRecord(string $table): iterable
    {
        return $this->reader->streamTable($this->path, $table);
    }

    public function collectUids(string $table): array
    {
        return $this->reader->collectUids($this->path, $table);
    }

    public function count(string $table): int
    {
        return $this->reader->count($this->path, $table);
    }

    public function getAuxiliaryData(): array
    {
        return $this->auxiliary();
    }

    /**
     * Materialisiert (einmalig, ein Datei-Durchlauf) alle Tabellen außer den
     * gestreamten pages/tt_content. Das sind die kleineren relationalen Bereiche.
     *
     * @return array<string, mixed>
     */
    private function auxiliary(): array
    {
        if ($this->auxiliary !== null) {
            return $this->auxiliary;
        }
        $tables = array_values(array_filter(
            $this->reader->tableNames($this->path),
            static fn(string $t): bool => !in_array($t, self::STREAMED, true)
        ));
        return $this->auxiliary = $this->reader->collectTables($this->path, $tables);
    }
}
