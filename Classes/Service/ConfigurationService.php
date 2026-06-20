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

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Zentrale, gecachte Quelle für die Robbi-Copy-Konfiguration.
 *
 * Bündelt das früher in fünf Klassen verstreute Laden von
 * EXT:imp_exp_nl/imp_exp_nl.yaml sowie das Einsammeln der Table-Registry
 * aus allen Extensions (Configuration/ImpExpNL.yaml).
 */
class ConfigurationService
{
    private const MAIN_CONFIG = 'EXT:imp_exp_nl/imp_exp_nl.yaml';

    private ?array $configCache = null;
    private ?array $tableCache = null;
    /** @var array<string, string>|null  Tabelle => Herkunft (Dateiname bzw. Extension-Key). */
    private ?array $tableSourceCache = null;

    public function __construct(
        private readonly YamlFileLoader $yamlFileLoader,
        private readonly PackageManager $packageManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Liefert die gemergte Hauptkonfiguration (imp_exp_nl.yaml).
     */
    public function getConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }
        try {
            $this->configCache = $this->yamlFileLoader->load(self::MAIN_CONFIG);
        } catch (\Exception $e) {
            $this->logger->warning('imp_exp_nl.yaml konnte nicht geladen werden: ' . $e->getMessage());
            $this->configCache = [];
        }
        return $this->configCache;
    }

    /**
     * FAL-Referenzen exportieren/importieren?
     */
    public function isFileReferencesEnabled(string $direction): bool
    {
        return !empty($this->getConfig()[$direction]['include']['file_references']);
    }

    /**
     * b13/container-Unterstützung aktiv?
     */
    public function isContainerSupportEnabled(): bool
    {
        return !empty($this->getConfig()['import']['container_support']);
    }

    /**
     * Sekunden, nach denen ein hängengebliebener Import-Lock als veraltet gilt.
     */
    public function getLockStaleSeconds(int $default = 3600): int
    {
        $value = (int)($this->getConfig()['import']['lock_stale_seconds'] ?? 0);
        return $value > 0 ? $value : $default;
    }

    /**
     * Records pro DataHandler-Batch.
     */
    public function getBatchSize(int $default = 500): int
    {
        $value = (int)($this->getConfig()['import']['batch_size'] ?? 0);
        return $value > 0 ? $value : $default;
    }

    /**
     * Standard-FAL-Storage, falls eine Referenz keine eigene Storage-Angabe trägt.
     */
    public function getFalStorageId(int $default = 1): int
    {
        $value = (int)($this->getConfig()['import']['fal']['storage_id'] ?? 0);
        return $value > 0 ? $value : $default;
    }

    /**
     * Stabiler Bezeichner des Quellsystems. Wird beim Export ins Manifest
     * geschrieben und beim Import zur Mapping-Auflösung genutzt. Mehrere
     * Quellsysteme bleiben dadurch unterscheidbar. Leer = Einzel-Quelle.
     */
    public function getSourceId(): string
    {
        return (string)($this->getConfig()['source_id'] ?? '');
    }

    /**
     * Soll ein abgebrochener Import automatisch zurückgerollt werden?
     * (Standard: ja – es bleibt kein halber Baum zurück.)
     */
    public function isAutoRollbackOnFailure(): bool
    {
        return (bool)($this->getConfig()['import']['auto_rollback_on_failure'] ?? true);
    }

    /**
     * Felder, in denen t3://page-Links umgeschrieben werden.
     *
     * @param string[] $fallback
     * @return string[]
     */
    public function getLinkRewriteFields(array $fallback = ['bodytext', 'pi_flexform']): array
    {
        $fields = $this->getConfig()['import']['link_rewrite']['fields'] ?? null;
        return (is_array($fields) && $fields !== []) ? $fields : $fallback;
    }

    /**
     * Alle registrierten Tabellen-Definitionen.
     * Merged imp_exp_nl.yaml und alle Extensions mit Configuration/ImpExpNL.yaml.
     *
     * @return array<string, array> Tabelle => Konfiguration
     */
    public function getRegisteredTables(): array
    {
        if ($this->tableCache !== null) {
            return $this->tableCache;
        }

        $tables = $this->getConfig()['impexpnl']['tables'] ?? [];
        $sources = array_fill_keys(array_keys($tables), 'imp_exp_nl.yaml');

        foreach ($this->getExtensionConfigFiles() as $packageKey => $file) {
            try {
                $ext = $this->yamlFileLoader->load($file);
                if (!empty($ext['impexpnl']['tables'])) {
                    $tables = array_merge($tables, $ext['impexpnl']['tables']);
                    foreach (array_keys($ext['impexpnl']['tables']) as $tableName) {
                        $sources[$tableName] = 'EXT:' . $packageKey;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('ImpExpNL.yaml fehlerhaft', ['ext' => $packageKey, 'error' => $e->getMessage()]);
            }
        }

        $this->tableCache = $tables;
        $this->tableSourceCache = $sources;
        return $tables;
    }

    /**
     * Herkunft jeder registrierten Tabelle (Dateiname bzw. Extension-Key) –
     * für aussagekräftige Validierungs-Meldungen.
     *
     * @return array<string, string> Tabelle => Herkunft
     */
    public function getTableSources(): array
    {
        if ($this->tableSourceCache === null) {
            $this->getRegisteredTables();
        }
        return $this->tableSourceCache ?? [];
    }

    /**
     * Alle aktiven Extensions, die eine Configuration/ImpExpNL.yaml mitliefern.
     *
     * @return array<string, string> packageKey => absoluter Dateipfad
     */
    public function getExtensionConfigFiles(): array
    {
        $files = [];
        try {
            foreach ($this->packageManager->getActivePackages() as $pkg) {
                $file = $pkg->getPackagePath() . 'Configuration/ImpExpNL.yaml';
                if (file_exists($file)) {
                    $files[$pkg->getPackageKey()] = $file;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Extension-Scan fehlgeschlagen: ' . $e->getMessage());
        }
        return $files;
    }
}
