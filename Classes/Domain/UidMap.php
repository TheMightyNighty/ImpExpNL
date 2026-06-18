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
 * Zuordnung von Quell-UIDs zu Ziel-UIDs je Tabelle.
 *
 * Ersetzt das früher als rohes Array herumgereichte Mapping und beseitigt damit
 * den Dual-State (Instanz-Property vs. per Referenz übergebenes Array).
 */
final class UidMap
{
    /**
     * @param array<string, array<int,int>> $map Tabelle => (alteUid => neueUid)
     */
    public function __construct(private array $map = []) {}

    /**
     * @param array<string, array<int,int>> $map
     */
    public static function fromArray(array $map): self
    {
        return new self($map);
    }

    public function set(string $table, int $oldUid, int $newUid): void
    {
        $this->map[$table][$oldUid] = $newUid;
    }

    public function get(string $table, int $oldUid): ?int
    {
        return $this->map[$table][$oldUid] ?? null;
    }

    public function has(string $table, int $oldUid): bool
    {
        return isset($this->map[$table][$oldUid]);
    }

    /**
     * @return array<int,int> alteUid => neueUid
     */
    public function forTable(string $table): array
    {
        return $this->map[$table] ?? [];
    }

    public function isEmpty(): bool
    {
        foreach ($this->map as $entries) {
            if (!empty($entries)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, array<int,int>>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}
