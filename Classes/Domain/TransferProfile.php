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
 * Aufgelöstes Transfer-Profil: verheiratet einen Asset-Transport (das „Wie") mit
 * Policy-Guardrails (das „Was"). Quelle ist `transferProfiles` in der YAML-Config.
 */
final class TransferProfile
{
    /**
     * @param array{type:string} $transport Aufgelöste assetTransport-Config (mind. `type`)
     * @param list<string> $allowedContext Erlaubte TYPO3-Application-Contexts (leer = überall)
     * @param list<string>|null $allowedTables Registry-Tabellen, die dieses Profil importieren
     *        darf (null = alle registrierten; leere Liste = keine). Begrenzt den Wirkbereich
     *        eines Imports über pages/tt_content/FAL hinaus.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $transport,
        public readonly ConflictStrategy $conflictStrategy,
        public readonly array $allowedContext,
        public readonly bool $requireDryRun,
        public readonly ?array $allowedTables = null,
    ) {}
}
