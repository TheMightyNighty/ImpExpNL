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

/**
 * PII/DSGVO-Scrubbing für prod→dev-Transfers. Anonymisiert konfigurierte Felder
 * der exportierten Records **vor** dem Schreiben (und vor der Prüfsumme), damit
 * personenbezogene Daten Produktion gar nicht erst verlassen.
 *
 * Konfiguration unter `export.sanitize` (Default aus):
 *   enabled: true
 *   hash_key: 'geheimes-salt'   # Schlüssel für die hash-Strategie (oder Env IMPEXPNL_SANITIZE_KEY)
 *   rules:
 *     fe_users:
 *       - { field: email,    strategy: hash }            # deterministisches Pseudonym
 *       - { field: username, strategy: hash }
 *       - { field: password, strategy: clear }           # leeren
 *       - { field: first_name, strategy: fixed, value: 'Anonym' }
 *
 * Strategien:
 *   - `fixed` (Default): fester Ersatzwert (`value`, Default leer)
 *   - `hash`: deterministisches Pseudonym – gleiche Eingabe → gleicher Wert.
 *     Mit konfiguriertem `hash_key` als HMAC-SHA256 (echte Pseudonymisierung:
 *     ohne Schlüssel nicht per Wörterbuch re-identifizierbar). Ohne Schlüssel
 *     nur ungesalzenes SHA256 – bei ratbaren Werten (E-Mail-Adressen!) trivial
 *     umkehrbar; es wird eine Warnung geloggt.
 *   - `clear`: leeren
 */
class SanitizeService
{
    private bool $missingKeyWarned = false;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string, mixed> $data Export-Datenblock (Tabelle → Records)
     * @param array<string, mixed> $rules Tabelle → Liste von Feldregeln (aus YAML, daher mixed)
     * @param string|null $hashKey Geheimer Schlüssel für die hash-Strategie (HMAC-SHA256)
     * @return array<string, mixed> sanitisierter Datenblock
     */
    public function sanitize(array $data, array $rules, ?string $hashKey = null): array
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
                    $data[$table][$index][$field] = $this->apply((string)$record[$field], $rule, $hashKey);
                }
            }
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function apply(string $value, array $rule, ?string $hashKey): string
    {
        return match ((string)($rule['strategy'] ?? 'fixed')) {
            'hash' => $value === '' ? '' : $this->pseudonymize($value, $hashKey),
            'clear' => '',
            default => (string)($rule['value'] ?? ''),
        };
    }

    private function pseudonymize(string $value, ?string $hashKey): string
    {
        if ($hashKey !== null && $hashKey !== '') {
            return hash_hmac('sha256', $value, $hashKey);
        }
        if (!$this->missingKeyWarned) {
            $this->missingKeyWarned = true;
            $this->logger->warning(
                'Sanitize: hash-Strategie ohne Schlüssel – ungesalzenes SHA256 ist bei ratbaren Werten '
                . '(z. B. E-Mail-Adressen) per Wörterbuch re-identifizierbar. export.sanitize.hash_key '
                . 'oder IMPEXPNL_SANITIZE_KEY setzen.'
            );
        }
        return hash('sha256', $value);
    }
}
