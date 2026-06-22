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
 * PII/DSGVO-Scrubbing für prod→dev-Transfers. Anonymisiert konfigurierte Felder
 * der exportierten Records **vor** dem Schreiben (und vor der Prüfsumme), damit
 * personenbezogene Daten Produktion gar nicht erst verlassen.
 *
 * Konfiguration unter `export.sanitize` (Default aus):
 *   enabled: true
 *   rules:
 *     fe_users:
 *       - { field: email,    strategy: hash }            # deterministischer Pseudonym
 *       - { field: username, strategy: hash }
 *       - { field: password, strategy: clear }           # leeren
 *       - { field: first_name, strategy: fixed, value: 'Anonym' }
 *
 * Strategien:
 *   - `fixed` (Default): fester Ersatzwert (`value`, Default leer)
 *   - `hash`: deterministischer Pseudonym (sha1) – gleiche Eingabe → gleicher Wert
 *   - `clear`: leeren
 */
class SanitizeService
{
    /**
     * @param array<string, mixed> $data Export-Datenblock (Tabelle → Records)
     * @param array<string, mixed> $rules Tabelle → Liste von Feldregeln (aus YAML, daher mixed)
     * @return array<string, mixed> sanitisierter Datenblock
     */
    public function sanitize(array $data, array $rules): array
    {
        foreach ($rules as $table => $fieldRules) {
            if (!isset($data[$table]) || !is_array($data[$table]) || !is_array($fieldRules)) {
                continue;
            }
            foreach ($data[$table] as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }
                foreach ($fieldRules as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $field = (string)($rule['field'] ?? '');
                    if ($field === '' || !array_key_exists($field, $record)) {
                        continue;
                    }
                    $data[$table][$index][$field] = $this->apply((string)$record[$field], $rule);
                }
            }
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function apply(string $value, array $rule): string
    {
        return match ((string)($rule['strategy'] ?? 'fixed')) {
            'hash' => $value === '' ? '' : sha1($value),
            'clear' => '',
            default => (string)($rule['value'] ?? ''),
        };
    }
}
