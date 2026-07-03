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
 *
 * Key-Rotation (optional):
 *   Mit gesetzter Key-ID (['signingKeyId'] bzw. IMPEXPNL_SIGNING_KEY_ID) wird die
 *   Signatur als `hmac-sha256:<key-id>:<hash>` geschrieben. Beim Verifizieren wird
 *   der Schlüssel über die Key-ID aufgelöst: aktiver Schlüssel oder ein Alt-Schlüssel
 *   aus ['signingKeys'] (Map key-id => Schlüssel). Signaturen ohne Key-ID werden
 *   gegen den aktiven und alle hinterlegten Alt-Schlüssel geprüft – so lassen sich
 *   Schlüssel wechseln, ohne dass Pakete aus der Übergangszeit ungültig werden.
 */
class IntegrityService
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

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
        if ($key === null) {
            return 'sha256:' . $this->computeHash($data, null);
        }
        $keyId = $this->getSigningKeyId();
        return 'hmac-sha256:' . ($keyId !== null ? $keyId . ':' : '') . $this->computeHash($data, $key);
    }

    /**
     * Verifiziert eine vorhandene Prüfsumme gegen die Daten.
     * Unterstützt das neue Schema (sha256:/hmac-sha256:) und das Legacy-Format.
     */
    public function verify(array $data, string $expected): bool
    {
        $key = $this->getSigningKey();

        if (str_starts_with($expected, 'hmac-sha256:')) {
            $rest = substr($expected, strlen('hmac-sha256:'));

            // Rotations-Format `hmac-sha256:<key-id>:<hash>`: Schlüssel gezielt
            // über die Key-ID auflösen (aktiver oder hinterlegter Alt-Schlüssel).
            if (str_contains($rest, ':')) {
                [$keyId] = explode(':', $rest, 2);
                $resolved = $this->getVerificationKeys()[$keyId] ?? null;
                if ($resolved === null) {
                    // Unbekannte Key-ID → nicht verifizierbar.
                    return false;
                }
                return hash_equals($expected, 'hmac-sha256:' . $keyId . ':' . $this->computeHash($data, $resolved));
            }

            // Format ohne Key-ID: gegen den aktiven und alle hinterlegten
            // Alt-Schlüssel prüfen (Übergangszeit einer Rotation).
            foreach ($this->getVerificationKeys() as $candidate) {
                if (hash_equals($expected, 'hmac-sha256:' . $this->computeHash($data, $candidate))) {
                    return true;
                }
            }
            return false;
        }

        // Downgrade-Schutz: Ist irgendein Signing-Key konfiguriert (aktiver oder
        // Alt-Schlüssel), sind ausschließlich HMAC-Signaturen gültig. Eine bloße
        // Prüfsumme (sha256:/Legacy) könnte sonst von jedem, der die Datei
        // manipuliert, einfach neu berechnet werden.
        if ($key !== null || $this->getVerificationKeys() !== []) {
            return false;
        }

        if (str_starts_with($expected, 'sha256:')) {
            return hash_equals($expected, 'sha256:' . $this->computeHash($data, null));
        }

        // Legacy (Format 1): roher SHA256 nur über pages + tt_content.
        // Auslaufend: wird mit Version 3.0 entfernt (deckt Registry-Tabellen,
        // FAL-Referenzen und IRRE-Relationen nicht ab).
        $this->logger?->warning(
            'Legacy-Prüfsumme (Export-Format 1) akzeptiert – dieses Format ist veraltet und wird '
            . 'in ImpExpNL 3.0 entfernt. Datei auf dem Quellsystem neu exportieren.'
        );
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
     * Trägt der Prüfsummen-String eine HMAC-Signatur (kein Downgrade auf bloße Prüfsumme)?
     */
    public function isHmacSignature(?string $checksum): bool
    {
        return is_string($checksum) && str_starts_with($checksum, 'hmac-sha256:');
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

    /**
     * Optionale Kennung des aktiven Schlüssels (für die Rotation). Quelle:
     * ['signingKeyId'] bzw. IMPEXPNL_SIGNING_KEY_ID. Erlaubt sind nur
     * [A-Za-z0-9._-], da die ID Teil des Signatur-Strings wird (kein ':').
     */
    private function getSigningKeyId(): ?string
    {
        $id = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $env = getenv('IMPEXPNL_SIGNING_KEY_ID');
            $id = is_string($env) ? $env : '';
        }
        $id = trim((string)$id);
        if ($id === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1) {
            $this->logger?->warning("Signing-Key-ID '$id' enthält unzulässige Zeichen und wird ignoriert (erlaubt: A-Z a-z 0-9 . _ -).");
            return null;
        }
        return $id;
    }

    /**
     * Alle für die Verifikation zulässigen Schlüssel: der aktive Schlüssel
     * (unter seiner Key-ID bzw. '' ohne ID) plus hinterlegte Alt-Schlüssel aus
     * ['signingKeys'] (Map key-id => Schlüssel). Der aktive Schlüssel gewinnt
     * bei ID-Kollision.
     *
     * @return array<string, string> key-id => Schlüssel
     */
    private function getVerificationKeys(): array
    {
        $keys = [];
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] ?? [];
        if (is_array($configured)) {
            foreach ($configured as $id => $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[(string)$id] = trim($key);
                }
            }
        }
        $active = $this->getSigningKey();
        if ($active !== null) {
            $keys[$this->getSigningKeyId() ?? ''] = $active;
        }
        return $keys;
    }
}
