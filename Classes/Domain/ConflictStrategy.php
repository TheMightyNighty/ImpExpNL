<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Domain;

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
