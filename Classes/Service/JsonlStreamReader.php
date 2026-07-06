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

/**
 * Zeilenweiser Leser für das JSONL-Export-Format (ein JSON-Objekt pro Zeile:
 * `{"_meta": …}` als erste Zeile, danach `{"_t": <tabelle>, "_r": <record>}`).
 *
 * Ermöglicht den streamenden Import: statt die gesamte Datei zu dekodieren,
 * wird sie je nach Bedarf mehrfach durchlaufen und auf eine Tabelle gefiltert.
 * Mehrere Datei-Durchläufe sind billig gegen den Speichergewinn (Peak = ein
 * Record statt der gesamten Datei).
 */
class JsonlStreamReader
{
    /**
     * Liefert den `_meta`-Block (erste nicht-leere Zeile mit `_meta`-Schlüssel).
     *
     * @return array<string, mixed>
     */
    public function readMeta(string $path): array
    {
        foreach ($this->readLines($path) as $obj) {
            if (array_key_exists('_meta', $obj)) {
                return is_array($obj['_meta']) ? $obj['_meta'] : [];
            }
        }
        return [];
    }

    /**
     * Streamt die Records genau einer Tabelle. Konstanter Speicher: es wird
     * immer nur eine Zeile gehalten.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamTable(string $path, string $table): \Generator
    {
        foreach ($this->readLines($path) as $obj) {
            if (($obj['_t'] ?? null) === $table && array_key_exists('_r', $obj) && is_array($obj['_r'])) {
                yield $obj['_r'];
            }
        }
    }

    /**
     * Sammelt nur die `uid`-Werte einer Tabelle (ints) – für Containment-Prüfungen,
     * ohne die Record-Inhalte im Speicher zu halten.
     *
     * @return list<int>
     */
    public function collectUids(string $path, string $table): array
    {
        $uids = [];
        foreach ($this->streamTable($path, $table) as $record) {
            if (isset($record['uid'])) {
                $uids[] = (int)$record['uid'];
            }
        }
        return $uids;
    }

    /**
     * Zählt die Records einer Tabelle, ohne sie zu halten.
     */
    public function count(string $path, string $table): int
    {
        $count = 0;
        foreach ($this->streamTable($path, $table) as $ignored) {
            $count++;
        }
        return $count;
    }

    /**
     * Materialisiert alle Records der genannten Tabellen in einem einzigen Datei-
     * Durchlauf. Für die kleineren, relationalen Bereiche (FAL, IRRE, Assets,
     * Registry) gedacht, die nicht gestreamt werden – NICHT für pages/tt_content.
     *
     * @param list<string> $tables
     * @return array<string, list<array<string, mixed>>>
     */
    public function collectTables(string $path, array $tables): array
    {
        $wanted = array_fill_keys($tables, true);
        $result = [];
        foreach ($this->readLines($path) as $obj) {
            $table = (string)($obj['_t'] ?? '');
            if ($table !== '' && isset($wanted[$table]) && array_key_exists('_r', $obj) && is_array($obj['_r'])) {
                $result[$table][] = $obj['_r'];
            }
        }
        return $result;
    }

    /**
     * Alle in der Datei vorkommenden Tabellennamen (ein Durchlauf). Für die
     * streamende Prüfsummenberechnung, die alle Tabellen in ksort-Reihenfolge braucht.
     *
     * @return list<string>
     */
    public function tableNames(string $path): array
    {
        $seen = [];
        foreach ($this->readLines($path) as $obj) {
            $table = (string)($obj['_t'] ?? '');
            if ($table !== '') {
                $seen[$table] = true;
            }
        }
        return array_keys($seen);
    }

    /**
     * Ein Datei-Durchlauf: liefert jedes dekodierte Zeilen-Objekt.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readLines(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("File not readable: $path");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
                    throw new \RuntimeException('JSONL error: invalid line.');
                }
                yield $obj;
            }
        } finally {
            fclose($handle);
        }
    }
}
