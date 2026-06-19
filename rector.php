<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

/*
 * Rector unterstützt Upgrades auf künftige PHP-/TYPO3-Versionen.
 * Prüfen:   composer ci:php:rector
 * Anwenden: composer ci:php:rector-fix
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
    ])
    ->withPhpSets(php82: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_82,
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    ->withImportNames(importShortClasses: false);
