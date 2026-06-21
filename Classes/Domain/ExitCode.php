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
 * Differenzierte Exit-Codes für CI/CD-Pipelines: Aufrufer können auf die Art des
 * Fehlers reagieren (z. B. Lock erneut versuchen, Konflikte eskalieren), statt nur
 * „0/!=0" zu sehen.
 */
final class ExitCode
{
    /** Erfolg. */
    public const OK = 0;

    /** Generischer/unerwarteter Fehler. */
    public const GENERIC = 1;

    /** Ungültige Konfiguration oder Profil (validate-config/check, Profil-Parsing). */
    public const INVALID_CONFIG = 2;

    /** Ein anderer Import hält den Lock (DB- oder Datei-Lock). */
    public const LOCK_ACTIVE = 3;

    /** Prüfsummen-/Signaturprüfung der Importdatei fehlgeschlagen. */
    public const INTEGRITY = 4;

    /** Konflikte erkannt und die Strategie verlangt Abbruch (conflict=abort). */
    public const CONFLICTS = 5;

    /** Import mitten drin abgebrochen; Teilimport wurde (auto-)zurückgerollt. */
    public const PARTIAL_ROLLBACK = 6;

    /** Referenzierte Dateien/Assets fehlen auf dem Zielsystem. */
    public const ASSETS_MISSING = 7;

    private function __construct() {}
}
