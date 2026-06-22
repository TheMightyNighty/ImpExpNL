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

namespace Robbi\ImpExpNL\Transport;

/**
 * Ein Asset-Transport verarbeitet das fertige Export-Ergebnis (Manifest + Asset-Liste)
 * nach dem Schreiben der Dateien. ImpExpNL baut bewusst **kein** rsync/S3 nach – der
 * Manifest ist der Vertrag; Transporte bleiben dünn und manifest-zentrisch.
 */
interface TransportInterface
{
    /** Eindeutiger Typ-Schlüssel (z. B. `external`, `bundle`). */
    public function getType(): string;

    /**
     * @param string $exportFile Absoluter Pfad zur geschriebenen Export-Datei (JSON/JSONL)
     * @param array<int, array{storage?:int, identifier?:string, size?:int, sha256?:string|null}> $assets Asset-Manifest
     * @param array<string, mixed> $config Transport-spezifische Konfiguration
     * @return array{type:string, artifact:string|null, message:string}
     */
    public function package(string $exportFile, array $assets, array $config): array;
}
