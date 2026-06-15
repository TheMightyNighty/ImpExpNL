<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Psr\Log\LoggerInterface;
use Robbi\RobbiCopy\Domain\ConflictStrategy;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Import-Profile: Wiederverwendbare Konfigurationen für häufige Import-Szenarien.
 *
 * Profile werden als YAML-Dateien unter var/robbicopy_profiles/ gespeichert:
 *
 *   # var/robbicopy_profiles/dev_to_live.yaml
 *   source_file: /var/www/html/var/export_dev.json
 *   target_pid: 456
 *   workspace: 1
 *   delta: true
 *   conflict: skip
 */
class ProfileService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Lädt ein Profil aus var/robbicopy_profiles/<name>.yaml
     *
     * @return array{source_file: string, target_pid: int, workspace: int, delta: bool, conflict: string, depth: int}
     */
    public function loadProfile(string $name): array
    {
        // Nur einfache Dateinamen zulassen (Path-Traversal-Schutz).
        if ($name !== basename($name) || str_contains($name, '..') || $name === '') {
            throw new \InvalidArgumentException(
                "Ungültiger Profilname: '$name'. Nur einfache Dateinamen ohne Pfadanteile erlaubt."
            );
        }

        $dir = Environment::getVarPath() . '/robbicopy_profiles';
        $file = $dir . '/' . $name . '.yaml';

        // Zusätzlicher realpath-Check nach Auflösung (robust gegen nicht existierendes Verzeichnis)
        $realDir = realpath($dir);
        if (file_exists($file) && ($realDir === false || !str_starts_with((string)realpath($file), $realDir))) {
            throw new \InvalidArgumentException(
                'Ungültiger Profilpfad: Auflösung führt außerhalb des Profilverzeichnisses.'
            );
        }

        if (!file_exists($file)) {
            throw new \RuntimeException(
                "Profil '$name' nicht gefunden. Erwartet: $file\n"
                . 'Verfügbare Profile: ' . implode(', ', $this->listProfiles())
            );
        }

        $content = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($file));
        if (!is_array($content)) {
            throw new \RuntimeException("Profil '$name' ist kein gültiges YAML.");
        }

        // Defaults setzen
        $profile = [
            'source_file' => $content['source_file'] ?? '',
            'target_pid' => (int)($content['target_pid'] ?? 0),
            'workspace' => (int)($content['workspace'] ?? 0),
            'delta' => (bool)($content['delta'] ?? false),
            'conflict' => $content['conflict'] ?? 'overwrite',
            'depth' => (int)($content['depth'] ?? 0),
        ];

        if (empty($profile['source_file'])) {
            throw new \RuntimeException("Profil '$name': 'source_file' fehlt.");
        }
        if ($profile['target_pid'] <= 0) {
            throw new \RuntimeException("Profil '$name': 'target_pid' muss > 0 sein.");
        }
        // Konflikt-Strategie früh validieren, mit Profil-Kontext in der Fehlermeldung.
        try {
            ConflictStrategy::fromInput($profile['conflict']);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("Profil '$name': " . $e->getMessage());
        }

        $this->logger->info('Profil geladen', ['name' => $name, 'config' => $profile]);
        return $profile;
    }

    /**
     * @return string[] Namen aller verfügbaren Profile.
     */
    public function listProfiles(): array
    {
        $dir = Environment::getVarPath() . '/robbicopy_profiles';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.yaml');
        return array_map(fn($f) => basename($f, '.yaml'), $files ?: []);
    }
}
