<?php

declare(strict_types=1);

/*
 * Code-Style auf Basis der offiziellen TYPO3 Coding Standards.
 * Ausführen:  composer ci:php:cs       (Prüfung)
 *             composer ci:php:cs-fix   (automatische Korrektur)
 */

if (!class_exists(\TYPO3\CodingStandards\CsFixerConfig::class)) {
    fwrite(STDERR, "typo3/coding-standards ist nicht installiert. Bitte 'composer install' ausführen.\n");
    exit(1);
}

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Tests')
    ->in(__DIR__ . '/Build')
    ->name('*.php');

return $config;
