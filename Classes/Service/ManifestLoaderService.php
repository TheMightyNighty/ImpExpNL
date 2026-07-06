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
use Robbi\ImpExpNL\Domain\InMemoryManifestReader;
use Robbi\ImpExpNL\Domain\ManifestReader;
use Robbi\ImpExpNL\Domain\StreamingManifestReader;
use Robbi\ImpExpNL\Exception\ImpExpException;
use Robbi\ImpExpNL\Exception\IntegrityException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Lädt und validiert eine Export-Datei (JSON/JSONL) zu einem {@see ManifestReader}.
 *
 * Bündelt die komplette Eingangs-Validierungskette des Imports:
 * Pfad-Containment (Projektverzeichnis, realpath), Dateigrößen-Limit
 * (import.max_file_size_mb), Format-Parsing sowie die Integritäts-/
 * Signaturprüfung inkl. Signaturpflicht (import.require_signature).
 *
 * JSON wird vollständig in den Speicher geladen ({@see InMemoryManifestReader}),
 * JSONL records-weise gestreamt ({@see StreamingManifestReader}) – inkl.
 * streamender Prüfsummen-/Signaturverifikation ohne Vollparse.
 *
 * Aus {@see ImportService} extrahiert, damit die sicherheitskritische
 * Eingangsprüfung eine eigene, überschaubare Einheit ist.
 */
class ManifestLoaderService
{
    /**
     * Ab dieser Dateigröße wird für .json vor dem Vollparse gewarnt und JSONL
     * empfohlen: mit MEMORY_FACTOR 8 entspricht das etwa dem PHP-Default-
     * memory_limit von 128 MB. Auch von {@see \Robbi\ImpExpNL\Command\ImportCommand}
     * für die Konsolen-Warnung genutzt.
     */
    public const LARGE_JSON_WARN_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly IntegrityService $integrityService,
        private readonly JsonlStreamReader $jsonlReader,
        private readonly LoggerInterface $logger
    ) {}

    public function load(string $jsonPath): ManifestReader
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

        // Dateigrößen-Limit als DoS-Guard (auch der streamende Pfad soll nicht
        // beliebig große Dateien ungefragt verarbeiten). 0 = unbegrenzt.
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

        return $isJsonl
            ? $this->loadStreaming($realPath)
            : $this->loadInMemory($realPath, $fileSize);
    }

    private function loadInMemory(string $realPath, int $fileSize): ManifestReader
    {
        // Weiche Warnung vor dem harten memory_limit-Guard: Große .json-Dateien
        // werden komplett geparst; der streamende JSONL-Pfad wäre der richtige Weg.
        if ($fileSize > self::LARGE_JSON_WARN_BYTES) {
            $this->logger->warning(sprintf(
                'Große JSON-Importdatei (%d MB) wird vollständig in den Speicher geparst. '
                . 'Für Exporte dieser Größe das streamende JSONL-Format verwenden (Export mit --jsonl).',
                (int)round($fileSize / 1048576)
            ));
        }

        // Plausibilitätsprüfung gegen das PHP memory_limit: Das dekodierte Array
        // benötigt ein Vielfaches der Dateigröße; ohne Prüfung endet das Parsen
        // in einem OOM-Fatal ohne verwertbare Fehlermeldung. Nur für den JSON-Pfad
        // relevant – JSONL wird gestreamt und braucht diesen Headroom nicht.
        $this->assertMemoryHeadroom($fileSize);

        $manifest = ExportManifest::fromArray($this->parseJsonFile($realPath));
        if (!$manifest->hasPages()) {
            throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');
        }

        $checksum = $manifest->getChecksum();
        $this->assertSignaturePolicy($checksum);

        if ($checksum !== null && !$this->integrityService->verify($manifest->toArray(), $checksum)) {
            throw $this->integrityFailed();
        }
        return new InMemoryManifestReader($manifest);
    }

    private function loadStreaming(string $realPath): ManifestReader
    {
        $meta = $this->jsonlReader->readMeta($realPath);
        $reader = new StreamingManifestReader($this->jsonlReader, $realPath, $meta);
        if (!$reader->hasPages()) {
            throw new \RuntimeException('"pages" muss ein nicht-leeres Array sein.');
        }

        $checksum = $reader->getChecksum();
        $this->assertSignaturePolicy($checksum);

        if ($checksum !== null && !$this->integrityService->verifyStreaming(
            $checksum,
            fn(?string $key): string => $this->integrityService->computeHashStreaming($this->hashTables($realPath), $key)
        )) {
            throw $this->integrityFailed();
        }
        return $reader;
    }

    /**
     * Alle Tabellen der JSONL-Datei in ksort-Reihenfolge, jeweils records-weise
     * gestreamt – Eingabe für {@see IntegrityService::computeHashStreaming()}.
     * Reihenfolge und Skip-Empty entsprechen dem Array-Weg (computeHash: ksort +
     * nur nicht-leere Tabellen; tableNames() liefert nur vorkommende Tabellen).
     *
     * @return \Generator<int, array{0:string, 1:iterable<array<string,mixed>>}>
     */
    private function hashTables(string $realPath): \Generator
    {
        $tableNames = $this->jsonlReader->tableNames($realPath);
        sort($tableNames);
        foreach ($tableNames as $name) {
            yield [$name, $this->jsonlReader->streamTable($realPath, $name)];
        }
    }

    /**
     * Signaturpflicht (import.require_signature): ohne gültige HMAC-Signatur wird
     * gar nicht erst verifiziert, sondern sofort abgewiesen. Verhindert sowohl das
     * Weglassen der Prüfsumme als auch den Downgrade auf eine bloße sha256-Prüfsumme.
     */
    private function assertSignaturePolicy(?string $checksum): void
    {
        if (!$this->configurationService->isSignatureRequired()) {
            return;
        }
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

    private function integrityFailed(): IntegrityException
    {
        return new IntegrityException(
            'Integritätsprüfung fehlgeschlagen: JSON wurde verändert oder die Signatur kann ohne '
            . 'konfigurierten Schlüssel (IMPEXPNL_SIGNING_KEY) nicht verifiziert werden.'
        );
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
}
