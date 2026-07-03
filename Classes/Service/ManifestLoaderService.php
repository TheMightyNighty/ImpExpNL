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

use Robbi\ImpExpNL\Domain\ExportManifest;
use Robbi\ImpExpNL\Exception\ImpExpException;
use Robbi\ImpExpNL\Exception\IntegrityException;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Lädt und validiert eine Export-Datei (JSON/JSONL) zum {@see ExportManifest}.
 *
 * Bündelt die komplette Eingangs-Validierungskette des Imports:
 * Pfad-Containment (Projektverzeichnis, realpath), Dateigrößen-Limit
 * (import.max_file_size_mb), Format-Parsing sowie die Integritäts-/
 * Signaturprüfung inkl. Signaturpflicht (import.require_signature).
 *
 * Aus {@see ImportService} extrahiert, damit die sicherheitskritische
 * Eingangsprüfung eine eigene, überschaubare Einheit ist.
 */
class ManifestLoaderService
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly IntegrityService $integrityService
    ) {}

    public function load(string $jsonPath): ExportManifest
    {
        if (!file_exists($jsonPath)) {
            throw new \RuntimeException("Datei nicht gefunden: $jsonPath");
        }

        // Schutz vor Symlink-/Path-Traversal.
        $realPath = realpath($jsonPath);
        $isJsonl = $realPath !== false && str_ends_with($realPath, '.jsonl');
        if ($realPath === false || (!str_ends_with($realPath, '.json') && !$isJsonl)) {
            throw new \RuntimeException("Ungültiger Dateipfad oder keine JSON/JSONL-Datei: $jsonPath");
        }

        // Mit Verzeichnistrenner vergleichen, damit Nachbarpfade mit gleichem
        // Präfix (/var/www/html-anderes) nicht als "innerhalb" durchgehen.
        $projectPath = Environment::getProjectPath();
        if ($projectPath !== '' && !str_starts_with($realPath, rtrim($projectPath, '/') . '/')) {
            throw new \RuntimeException("Importdatei liegt außerhalb des Projektverzeichnisses: $jsonPath");
        }

        // Prüfung vor dem Parsen: JSON/JSONL wird derzeit vollständig in den
        // Speicher geladen; das Limit bricht übergroße Dateien mit Fehlermeldung
        // ab, bevor der Prozess in ein OOM läuft.
        $maxBytes = $this->configurationService->getImportMaxFileSizeBytes();
        $fileSize = (int)filesize($realPath);
        if ($maxBytes > 0 && $fileSize > $maxBytes) {
            throw new ImpExpException(sprintf(
                'Importdatei zu groß: %d MB (Limit: %d MB, konfigurierbar über import.max_file_size_mb; 0 = unbegrenzt). '
                . 'Datei prüfen, Limit erhöhen oder den Export aufteilen (--pages/--since).',
                (int)round($fileSize / 1048576),
                (int)round($maxBytes / 1048576)
            ));
        }

        // Plausibilitätsprüfung gegen das PHP memory_limit: Das dekodierte Array
        // benötigt ein Vielfaches der Dateigröße; ohne Prüfung endet das Parsen
        // in einem OOM-Fatal ohne verwertbare Fehlermeldung.
        $this->assertMemoryHeadroom($fileSize);

        $data = $isJsonl ? $this->parseJsonlFile($realPath) : $this->parseJsonFile($realPath);

        $manifest = ExportManifest::fromArray($data);
        if (!$manifest->hasPages()) {
            throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');
        }

        $checksum = $manifest->getChecksum();

        // Signaturpflicht (import.require_signature): ohne gültige HMAC-Signatur wird
        // gar nicht erst verifiziert, sondern sofort abgewiesen. Verhindert sowohl das
        // Weglassen der Prüfsumme als auch den Downgrade auf eine bloße sha256-Prüfsumme.
        if ($this->configurationService->isSignatureRequired()) {
            if (!$this->integrityService->hasSigningKey()) {
                throw new IntegrityException(
                    'import.require_signature ist aktiv, aber es ist kein Signing-Key konfiguriert '
                    . '(IMPEXPNL_SIGNING_KEY bzw. EXTENSIONS/imp_exp_nl/signingKey). Import nicht möglich.'
                );
            }
            if (!$this->integrityService->isHmacSignature($checksum)) {
                throw new IntegrityException(
                    'Signaturpflicht aktiv (import.require_signature): Die Importdatei trägt keine '
                    . 'HMAC-Signatur (hmac-sha256:). Datei auf dem Quellsystem mit identischem '
                    . 'Signing-Key neu exportieren.'
                );
            }
        }

        if ($checksum !== null && !$this->integrityService->verify($manifest->toArray(), $checksum)) {
            throw new IntegrityException(
                'Integritätsprüfung fehlgeschlagen: JSON wurde verändert oder die Signatur kann ohne '
                . 'konfigurierten Schlüssel (IMPEXPNL_SIGNING_KEY) nicht verifiziert werden.'
            );
        }
        return $manifest;
    }

    /**
     * Geschätzter Speicherfaktor: dekodiertes PHP-Array vs. JSON-Dateigröße.
     * Konservativ gewählt (viele kleine Strings/Arrays je Record).
     */
    private const MEMORY_FACTOR = 8;

    private function assertMemoryHeadroom(int $fileSize): void
    {
        $limit = $this->parseMemoryLimit((string)ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // -1 = unbegrenzt
        }
        $estimated = memory_get_usage(true) + $fileSize * self::MEMORY_FACTOR;
        if ($estimated > $limit) {
            throw new ImpExpException(sprintf(
                'PHP memory_limit (%s) reicht voraussichtlich nicht für das Parsen der %d-MB-Importdatei '
                . '(geschätzter Bedarf ~%d MB). memory_limit erhöhen (z. B. php -d memory_limit=1G vendor/bin/typo3 …) '
                . 'oder den Export aufteilen (--pages/--since).',
                (string)ini_get('memory_limit'),
                (int)round($fileSize / 1048576),
                (int)round($estimated / 1048576)
            ));
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int)$limit;
        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonFile(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON-Fehler: ' . json_last_error_msg());
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Liest eine JSONL-Datei zeilenweise ein (ein JSON-Objekt pro Zeile),
     * ohne die gesamte Datei als einen String zu dekodieren.
     *
     * @return array<string, mixed>
     */
    private function parseJsonlFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Datei nicht lesbar: $path");
        }
        $data = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
                    throw new \RuntimeException('JSONL-Fehler: ungültige Zeile.');
                }
                if (array_key_exists('_meta', $obj)) {
                    $data['_meta'] = $obj['_meta'];
                    continue;
                }
                if (isset($obj['_t']) && array_key_exists('_r', $obj)) {
                    $data[(string)$obj['_t']][] = $obj['_r'];
                }
            }
        } finally {
            fclose($handle);
        }
        return $data;
    }
}
