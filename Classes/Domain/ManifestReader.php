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
 * Einheitliches Lesemodell einer Export-Datei für den Import – unabhängig davon,
 * ob sie als JSON vollständig im Speicher liegt ({@see InMemoryManifestReader})
 * oder als JSONL gestreamt wird ({@see StreamingManifestReader}).
 *
 * Große, zahlreiche Tabellen (pages, tt_content) werden über {@see eachRecord()}
 * records-weise konsumiert; die kleineren, relationalen Bereiche (FAL, IRRE,
 * Assets, Registry, Site-Config) über die benannten Zugriffe bzw.
 * {@see getAuxiliaryData()} materialisiert.
 */
interface ManifestReader
{
    /** @return array<string, mixed> */
    public function getMeta(): array;

    public function getSourceId(): string;

    public function getChecksum(): ?string;

    public function hasPages(): bool;

    /** @return array<int, array<string, mixed>> */
    public function getSiteConfig(): array;

    /** @return array<int, array{storage?:int, identifier?:string, size?:int, sha256?:string|null}> */
    public function getAssets(): array;

    /** @return array<int, array<string, mixed>> */
    public function getFileReferences(): array;

    /** @return array<int, array<string, mixed>> */
    public function getIrreRelations(): array;

    /**
     * Records einer Tabelle records-weise (für pages/tt_content streamend).
     *
     * @return iterable<array<string, mixed>>
     */
    public function eachRecord(string $table): iterable;

    /**
     * Nur die uid-Werte einer Tabelle (ints) für Containment-Prüfungen.
     *
     * @return list<int>
     */
    public function collectUids(string $table): array;

    public function count(string $table): int;

    /**
     * Alle Tabellen außer pages/tt_content/_meta – Grundlage für den Registry-Import
     * und das Post-Import-Event. Bewusst OHNE den pages/tt_content-Bulk: deren
     * Ergebnis steht vollständig in der UID-Map, der Event trägt sie nicht erneut.
     *
     * @return array<string, mixed>
     */
    public function getAuxiliaryData(): array;
}
