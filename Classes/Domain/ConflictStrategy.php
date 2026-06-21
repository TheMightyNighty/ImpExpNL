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
 * Strategie zur Behandlung von Konflikten im Delta-Import.
 *
 * Ein Konflikt liegt vor, wenn der Zielrecord einen neueren Zeitstempel hat
 * als der Export (lokal nach dem Export bearbeitet).
 */
enum ConflictStrategy: string
{
    /** Export überschreibt die lokale Änderung (Standard). */
    case Overwrite = 'overwrite';

    /** Records mit Konflikt werden übersprungen. */
    case Skip = 'skip';

    /** Pro Konflikt wird interaktiv nachgefragt. */
    case Ask = 'ask';

    /** Beim ersten Konflikt wird der gesamte Import abgebrochen (CI-tauglich, Exit-Code 5). */
    case Abort = 'abort';

    /**
     * Normalisiert eine Eingabe (CLI/Profil) zu einer Strategie.
     * Ungültige Werte führen zu einem Fehler, statt still wie Overwrite zu wirken.
     */
    public static function fromInput(string|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null || $value === '') {
            return self::Overwrite;
        }
        $strategy = self::tryFrom($value);
        if ($strategy === null) {
            throw new \InvalidArgumentException(sprintf(
                "Ungültige Konflikt-Strategie '%s'. Erlaubt: %s.",
                $value,
                implode(', ', array_map(static fn(self $c) => $c->value, self::cases()))
            ));
        }
        return $strategy;
    }
}
