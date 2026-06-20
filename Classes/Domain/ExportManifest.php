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
 * Typisiertes Lesemodell für eine Export-Datei.
 *
 * Kapselt den rohen Datenblock und bietet benannte Zugriffe auf die bekannten
 * Top-Level-Bereiche. Registry-Tabellen werden nicht einzeln getypt und über
 * toArray() bzw. table() angesprochen.
 */
final class ExportManifest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPages(): array
    {
        return $this->data['pages'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTtContent(): array
    {
        return $this->data['tt_content'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFileReferences(): array
    {
        return $this->data['sys_file_reference'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIrreRelations(): array
    {
        return $this->data['irre_relations'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSiteConfig(): array
    {
        return $this->data['_site_config'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->data['_meta'] ?? [];
    }

    /**
     * Stabiler Bezeichner des Quellsystems (für die Mapping-Auflösung beim
     * idempotenten Import). Leer, wenn der Export keine Quelle deklariert.
     */
    public function getSourceId(): string
    {
        return (string)($this->data['_meta']['source_id'] ?? '');
    }

    public function getChecksum(): ?string
    {
        $checksum = $this->data['_meta']['checksum'] ?? null;
        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    public function hasPages(): bool
    {
        return !empty($this->data['pages']) && is_array($this->data['pages']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
