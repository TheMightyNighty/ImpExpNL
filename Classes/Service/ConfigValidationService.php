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

use Robbi\ImpExpNL\Domain\ConflictStrategy;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Validiert die (gemergte) Table-Registry gegen das tatsächliche Datenbank-/TCA-
 * Schema und liefert klare, umsetzbare Befunde – inkl. Herkunft (Extension-Key)
 * und „Did you mean?"-Vorschlägen. Genutzt von
 * {@see \Robbi\ImpExpNL\Command\ValidateConfigCommand} und
 * {@see \Robbi\ImpExpNL\Command\CheckCommand}; gezielt testbar, da die zu prüfende
 * Konfiguration als Parameter übergeben wird.
 */
class ConfigValidationService
{
    private const VALID_TYPES = ['mm', 'record'];
    private const VALID_TRANSPORT_TYPES = ['external', 'bundle', 'push'];

    /** @var array<string, array<string, true>> Spalten je Tabelle (Cache). */
    private array $columnCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory
    ) {}

    /**
     * @param array<string, array<string, mixed>> $tables   Registry (Tabelle => Konfiguration)
     * @param string[]                            $linkRewriteFields  Felder aus import.link_rewrite.fields
     * @param array<string, string>               $sources  Tabelle => Herkunft (Extension-Key/Datei)
     * @return array<int, array{level:string, message:string}>  Befunde (level: error|warning)
     */
    public function validate(array $tables, array $linkRewriteFields = [], string $linkRewriteTable = 'tt_content', array $sources = []): array
    {
        $issues = [];

        foreach ($tables as $table => $config) {
            $table = (string)$table;
            $source = $sources[$table] ?? null;

            if (!$this->tableExists($table)) {
                // Warnung statt Fehler: Die mitgelieferten Profile registrieren optionale
                // Ziel-Extensions; Export/Import überspringen fehlende Tabellen.
                $issues[] = $this->warning("Table \"$table\" is registered but does not exist in the database – will be skipped on export/import (extension not installed?).", $source);
                continue;
            }

            $type = (string)($config['type'] ?? '');
            if (!in_array($type, self::VALID_TYPES, true)) {
                $issues[] = $this->error("Table \"$table\": invalid or missing type \"$type\" (allowed: " . implode(', ', self::VALID_TYPES) . ').', $source);
                continue;
            }

            $issues = array_merge(
                $issues,
                $type === 'record'
                    ? $this->validateRecord($table, $config, $source)
                    : $this->validateMm($table, $config, $source)
            );
        }

        foreach ($linkRewriteFields as $field) {
            $field = (string)$field;
            if ($this->tableExists($linkRewriteTable) && !$this->columnExists($linkRewriteTable, $field)) {
                $issues[] = $this->error(
                    "link_rewrite: field \"$field\" does not exist in table \"$linkRewriteTable\"." . $this->didYouMean($linkRewriteTable, $field)
                );
            }
        }

        return $issues;
    }

    /**
     * Prüft Transfer-Profile und Asset-Transporte aus der YAML-Config.
     *
     * @param array<string, mixed> $profiles transferProfiles
     * @param array<string, mixed> $transports assetTransports
     * @return array<int, array{level:string, message:string}>
     */
    public function validateTransferProfiles(array $profiles, array $transports): array
    {
        $issues = [];

        foreach ($transports as $name => $config) {
            $type = is_array($config) ? (string)($config['type'] ?? '') : '';
            if (!in_array($type, self::VALID_TRANSPORT_TYPES, true)) {
                $issues[] = $this->error(sprintf(
                    'assetTransport "%s": invalid type "%s" (allowed: %s).',
                    $name,
                    $type,
                    implode(', ', self::VALID_TRANSPORT_TYPES)
                ));
            }
        }

        foreach ($profiles as $name => $config) {
            if (!is_array($config)) {
                $issues[] = $this->error("transferProfile \"$name\": invalid definition (a mapping is expected).");
                continue;
            }
            $transport = (string)($config['transport'] ?? 'external');
            if (!isset($transports[$transport]) && !in_array($transport, self::VALID_TRANSPORT_TYPES, true)) {
                $issues[] = $this->error(sprintf(
                    'transferProfile "%s": transport "%s" is neither a defined assetTransport nor a known type (%s).',
                    $name,
                    $transport,
                    implode(', ', self::VALID_TRANSPORT_TYPES)
                ));
            }
            if (isset($config['conflictStrategy'])) {
                try {
                    ConflictStrategy::fromInput((string)$config['conflictStrategy']);
                } catch (\InvalidArgumentException $e) {
                    $issues[] = $this->error("transferProfile \"$name\": " . $e->getMessage());
                }
            }
            $context = $config['allowedContext'] ?? [];
            if (!is_array($context) || $context === []) {
                $issues[] = $this->warning("transferProfile \"$name\": \"allowedContext\" is empty – the profile is runnable in every environment (no dev/prod binding).");
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{level:string, message:string}>
     */
    private function validateRecord(string $table, array $config, ?string $source): array
    {
        $issues = [];

        $pidField = (string)($config['pid_field'] ?? '');
        if ($pidField === '') {
            $issues[] = $this->error("Table \"$table\" (record): \"pid_field\" is missing.", $source);
        } elseif (!$this->columnExists($table, $pidField)) {
            $issues[] = $this->error("Table \"$table\" (record): pid_field \"$pidField\" does not exist." . $this->didYouMean($table, $pidField), $source);
        }

        foreach ((array)($config['rewrite_links'] ?? []) as $field) {
            $field = (string)$field;
            if (!$this->columnExists($table, $field)) {
                $issues[] = $this->error("Table \"$table\" (record): rewrite_links field \"$field\" does not exist." . $this->didYouMean($table, $field), $source);
            }
        }

        // Ohne uid_remap kennt der Rollback die erzeugten UIDs nicht.
        if (empty($config['uid_remap'])) {
            $issues[] = $this->warning("Table \"$table\" (record): \"uid_remap\" is not active – records in this table will not be captured on rollback.", $source);
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{level:string, message:string}>
     */
    private function validateMm(string $table, array $config, ?string $source): array
    {
        $issues = [];

        foreach (['match_field', 'match_tablenames_field'] as $key) {
            $field = (string)($config[$key] ?? '');
            if ($field === '') {
                // match_tablenames_field ist optional, match_field nicht.
                if ($key === 'match_field') {
                    $issues[] = $this->error("Table \"$table\" (mm): \"match_field\" is missing.", $source);
                }
                continue;
            }
            if (!$this->columnExists($table, $field)) {
                $issues[] = $this->error("Table \"$table\" (mm): $key \"$field\" does not exist." . $this->didYouMean($table, $field), $source);
            }
        }

        $matchTables = (array)($config['match_tables'] ?? []);
        if ($matchTables === []) {
            $issues[] = $this->warning("Table \"$table\" (mm): \"match_tables\" is empty – MM matching will then apply to no table.", $source);
        }
        foreach ($matchTables as $matchTable) {
            if (!$this->tcaSchemaFactory->has((string)$matchTable)) {
                // Warnung statt Fehler: match_tables kann optionale Extension-Tabellen nennen.
                $issues[] = $this->warning("Table \"$table\" (mm): match_tables entry \"$matchTable\" is not a known TCA table – ignored during matching (extension not installed?).", $source);
            }
        }

        return $issues;
    }

    /**
     * Schlägt die nächstgelegene existierende Spalte vor (Tippfehler-Hilfe).
     */
    private function didYouMean(string $table, string $field): string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (array_keys($this->columns($table)) as $column) {
            $distance = levenshtein($field, $column);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $column;
            }
        }
        $threshold = max(1, (int)floor(strlen($field) / 3));
        return ($best !== null && $bestDistance > 0 && $bestDistance <= $threshold)
            ? " Did you mean \"$best\"?"
            : '';
    }

    private function tableExists(string $table): bool
    {
        try {
            $schemaManager = $this->connectionPool->getConnectionForTable($table)->createSchemaManager();
            return in_array($table, $schemaManager->listTableNames(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return isset($this->columns($table)[$column]);
    }

    /**
     * @return array<string, true> Spaltennamen der Tabelle (gecacht).
     */
    private function columns(string $table): array
    {
        if (!isset($this->columnCache[$table])) {
            $names = [];
            try {
                $columns = $this->connectionPool->getConnectionForTable($table)->createSchemaManager()
                    ->introspectTable($table)->getColumns();
                foreach ($columns as $col) {
                    $names[$col->getName()] = true;
                }
            } catch (\Throwable) {
                // Tabelle nicht introspizierbar -> als „keine Spalten“ behandeln.
            }
            $this->columnCache[$table] = $names;
        }
        return $this->columnCache[$table];
    }

    /** @return array{level:string, message:string} */
    private function error(string $message, ?string $source = null): array
    {
        return ['level' => 'error', 'message' => $message . $this->sourceSuffix($source)];
    }

    /** @return array{level:string, message:string} */
    private function warning(string $message, ?string $source = null): array
    {
        return ['level' => 'warning', 'message' => $message . $this->sourceSuffix($source)];
    }

    private function sourceSuffix(?string $source): string
    {
        return ($source !== null && $source !== '') ? " [$source]" : '';
    }
}
