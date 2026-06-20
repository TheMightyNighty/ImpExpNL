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
 * Integritäts- und (optional) Manipulationsschutz für Export-Dateien.
 *
 * Es gibt zwei Stufen:
 *
 *  1. Korruptionsschutz (Standard): SHA256 über den GESAMTEN Datenblock
 *     (alle Tabellen, nicht nur pages + tt_content). Erkennt versehentliche
 *     Beschädigung der Datei. Bietet KEINEN Schutz gegen gezielte Manipulation,
 *     da jeder die Prüfsumme neu berechnen kann.
 *
 *  2. Manipulationsschutz (optional): Ist ein geheimer Schlüssel konfiguriert,
 *     wird statt SHA256 ein HMAC-SHA256 gebildet. Ohne Kenntnis des Schlüssels
 *     kann die Signatur nicht neu erzeugt werden.
 *
 * Schlüssel-Quellen (in dieser Reihenfolge):
 *   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey']
 *   Umgebungsvariable IMPEXPNL_SIGNING_KEY
 */
class IntegrityService
{
    /**
     * Version des Prüfsummen-/Export-Formats.
     * 1 = Legacy (SHA256 nur über pages + tt_content)
     * 2 = SHA256/HMAC über den gesamten Datenblock (mit Schema-Präfix)
     */
    public const FORMAT_VERSION = 2;

    /**
     * Berechnet die Prüfsumme/Signatur über den gesamten Datenblock.
     * Der _meta-Block wird ausgeklammert (er enthält die Prüfsumme selbst).
     */
    public function sign(array $data): string
    {
        $key = $this->getSigningKey();
        return $key !== null
            ? 'hmac-sha256:' . $this->computeHash($data, $key)
            : 'sha256:' . $this->computeHash($data, null);
    }

    /**
     * Verifiziert eine vorhandene Prüfsumme gegen die Daten.
     * Unterstützt das neue Schema (sha256:/hmac-sha256:) und das Legacy-Format.
     */
    public function verify(array $data, string $expected): bool
    {
        if (str_starts_with($expected, 'hmac-sha256:')) {
            $key = $this->getSigningKey();
            if ($key === null) {
                // Signierte Datei, aber kein Schlüssel vorhanden → kann nicht verifiziert werden.
                return false;
            }
            return hash_equals($expected, 'hmac-sha256:' . $this->computeHash($data, $key));
        }

        if (str_starts_with($expected, 'sha256:')) {
            return hash_equals($expected, 'sha256:' . $this->computeHash($data, null));
        }

        // Legacy (Format 1): roher SHA256 nur über pages + tt_content
        $legacy = hash('sha256', json_encode($data['pages'] ?? []) . json_encode($data['tt_content'] ?? []));
        return hash_equals($expected, $legacy);
    }

    /**
     * Inkrementeller, reihenfolge-unabhängiger Hash über alle Datentabellen.
     *
     * Statt den gesamten Datensatz in einen einzigen String zu serialisieren
     * (Speicher-Peak bei großen Bäumen), wird der Hash-Kontext pro Record
     * fortgeschrieben. Der Spitzenbedarf bleibt damit bei der Größe eines
     * einzelnen Records statt des gesamten Exports.
     */
    private function computeHash(array $data, ?string $key): string
    {
        unset($data['_meta']);
        ksort($data);

        $ctx = $key !== null ? hash_init('sha256', HASH_HMAC, $key) : hash_init('sha256');

        foreach ($data as $tableName => $value) {
            // Leere Tabellen überspringen, damit JSON- und JSONL-Format (in dem
            // leere Tabellen schlicht keine Zeilen erzeugen) denselben Hash liefern.
            if ($value === [] || $value === null) {
                continue;
            }
            hash_update($ctx, $tableName . "\x00");
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $record) {
                    hash_update($ctx, $this->encodeCanonical($record) . "\x00");
                }
            } else {
                hash_update($ctx, $this->encodeCanonical($value) . "\x00");
            }
        }

        return hash_final($ctx);
    }

    private function encodeCanonical(mixed $value): string
    {
        if (is_array($value)) {
            $this->ksortRecursive($value);
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
        ksort($array);
    }

    /**
     * Ist ein Signing-Key konfiguriert (HMAC-Signaturschutz aktiv)?
     */
    public function hasSigningKey(): bool
    {
        return $this->getSigningKey() !== null;
    }

    private function getSigningKey(): ?string
    {
        $key = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            $env = getenv('IMPEXPNL_SIGNING_KEY');
            $key = is_string($env) ? $env : '';
        }
        $key = trim((string)$key);
        return $key !== '' ? $key : null;
    }
}
