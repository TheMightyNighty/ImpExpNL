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
 * Signierter Transfer-Nachweis (Audit-Artefakt) zu einem abgeschlossenen Import.
 *
 * Baut aus dem Import-Protokoll (tx_impexpnl_import_log) einen strukturierten
 * Bericht und signiert ihn über den {@see IntegrityService} — mit Signing-Key
 * als HMAC-SHA256 (inkl. Key-ID/Rotation), ohne Key als bloße sha256-Prüfsumme.
 * Damit trägt derselbe Schlüssel, der die Importdatei schützt, auch den
 * Nachweis; ein Auditor verifiziert mit `impexpnl:report --verify`.
 *
 * Der Bericht ist bewusst selbsttragend: Er referenziert die Quelldatei über
 * ihren sha256 (sofern sie noch vorliegt), nicht über ihren Inhalt — der
 * Nachweis bleibt klein und gültig, auch wenn die Datei später wegrotiert wird.
 */
class ReportService
{
    /** Version des Berichtsformats (für spätere Erweiterungen). */
    public const REPORT_VERSION = 1;

    public function __construct(
        private readonly ImportLogRepository $importLogRepository,
        private readonly IntegrityService $integrityService
    ) {}

    /**
     * Baut und signiert den Nachweis für einen Import. Liefert das komplette
     * Dokument (report + signature) oder null, wenn das Protokoll fehlt.
     *
     * @return array{report: array<string, mixed>, signature: string}|null
     */
    public function build(?string $importId = null): ?array
    {
        $row = $importId !== null
            ? $this->importLogRepository->findById($importId)
            : $this->importLogRepository->findLatest();
        if ($row === null) {
            return null;
        }

        $uidMap = json_decode((string)$row['uid_map'], true);
        $uidMap = is_array($uidMap) ? $uidMap : [];

        // Interne Schlüssel (_placed_files u. ä.) sind kein Record-Bestand.
        $records = [];
        $total = 0;
        foreach ($uidMap as $table => $map) {
            if (str_starts_with((string)$table, '_') || !is_array($map)) {
                continue;
            }
            $records[(string)$table] = count($map);
            $total += count($map);
        }
        ksort($records);

        $placedFiles = $uidMap['_placed_files'] ?? [];

        $report = [
            'report_version' => self::REPORT_VERSION,
            'generated_at' => date('c'),
            'generated_on' => gethostname() ?: 'unknown',
            'import' => [
                'import_id' => (string)$row['import_id'],
                'imported_at' => date('c', (int)$row['tstamp']),
                'workspace_id' => (int)$row['workspace_id'],
                'delta' => (bool)$row['delta_mode'],
                'source_file' => (string)$row['source_file'],
            ],
            'records' => $records,
            'records_total' => $total,
            'assets_placed' => is_array($placedFiles) ? count($placedFiles) : 0,
            'source' => $this->describeSourceFile((string)$row['source_file']),
        ];

        return [
            'report' => $report,
            'signature' => $this->integrityService->sign($report),
        ];
    }

    /**
     * Verifiziert ein Nachweis-Dokument (Struktur + Signatur). Die Signaturprüfung
     * unterliegt denselben Regeln wie die Importdatei (Downgrade-Sperre,
     * Key-Rotation über die Key-ID).
     *
     * @param array<string, mixed> $document Dekodiertes JSON ({report, signature})
     */
    public function verify(array $document): bool
    {
        $report = $document['report'] ?? null;
        $signature = $document['signature'] ?? null;
        if (!is_array($report) || !is_string($signature) || $signature === '') {
            return false;
        }
        return $this->integrityService->verify($report, $signature);
    }

    /**
     * Pins die Quelldatei über sha256 + Größe, sofern sie noch existiert.
     * Nach einer Rotation/Löschung bleibt der Nachweis gültig — dann ohne Hash.
     *
     * @return array{available: bool, sha256?: string, size_bytes?: int}
     */
    private function describeSourceFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return ['available' => false];
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            return ['available' => false];
        }
        return [
            'available' => true,
            'sha256' => $hash,
            'size_bytes' => (int)filesize($path),
        ];
    }
}
