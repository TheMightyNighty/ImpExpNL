<?php
declare(strict_types=1);

/**
 * Bootstrap für Unit-Tests.
 * Lädt den Composer-Autoloader, damit die Klassen gefunden werden.
 * Für Functional-Tests wird das TYPO3 Testing Framework seinen eigenen Bootstrap nutzen.
 */

$autoloadPaths = [
    // Im TYPO3-Projekt (packages/robbi_copy/)
    __DIR__ . '/../../../../vendor/autoload.php',
    // Standalone (für CI/CD)
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        return;
    }
}

throw new \RuntimeException(
    'Composer autoload.php nicht gefunden. Bitte "composer install" ausführen.'
);
