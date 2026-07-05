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

use Robbi\ImpExpNL\Domain\SystemFields;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Bereitet Quell-Records für den DataHandler auf: filtert Systemfelder, auf dem
 * Ziel nicht (mehr) vorhandene Spalten sowie Relations-Container-Felder heraus.
 *
 * Aus {@see ImportService} extrahiert und von {@see TableRegistryService}
 * mitgenutzt (gemeinsame Relations-Container-Erkennung statt Duplikat).
 */
class RecordDataBuilder
{
    /** @var array<string, array<string, true>> Vorhandene DB-Spalten je Tabelle (Cache). */
    private array $knownColumnsCache = [];

    /** @var array<string, array<string, true>> Relations-Container-Felder je Tabelle (Cache). */
    private array $relationFieldCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory
    ) {}

    /**
     * Baut die Record-Daten für den DataHandler. Ohne Tabellenname wird nur nach
     * Systemfeldern gefiltert (kein Spalten-/Relations-Abgleich).
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function build(array $source, string $table = ''): array
    {
        $knownColumns = $table !== '' ? $this->knownColumns($table) : [];
        $relationFields = $table !== '' ? $this->relationContainerFields($table) : [];
        $data = [];
        foreach ($source as $field => $value) {
            if (in_array($field, SystemFields::EXCLUDED, true)) {
                continue;
            }
            // Wenn Spalten-Info vorhanden: nur tatsächlich existierende Felder übernehmen.
            if (!empty($knownColumns) && !isset($knownColumns[$field])) {
                continue;
            }
            // Relations-Container (inline/file/category) tragen nur einen Count und werden
            // separat behandelt (sys_file_reference / IRRE / Registry). Direkt gesetzt würden
            // sie u. a. den DataMapProcessor bei Übersetzungen brechen (trimExplode auf int).
            if (isset($relationFields[$field])) {
                continue;
            }
            $data[$field] = $value;
        }
        return $data;
    }

    /**
     * Relations-Container-Felder (inline/file/category) einer Tabelle – diese tragen nur
     * einen Zähler und dürfen nicht direkt in die Datamap übernommen werden.
     *
     * @return array<string, true> Feldname → true
     */
    public function relationContainerFields(string $table): array
    {
        if (isset($this->relationFieldCache[$table])) {
            return $this->relationFieldCache[$table];
        }
        $fields = [];
        try {
            if ($this->tcaSchemaFactory->has($table)) {
                foreach ($this->tcaSchemaFactory->get($table)->getFields() as $field) {
                    if (
                        $field->isType(TableColumnType::INLINE)
                        || $field->isType(TableColumnType::FILE)
                        || $field->isType(TableColumnType::CATEGORY)
                    ) {
                        $fields[$field->getName()] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // TCA nicht verfügbar -> keine Filterung.
        }
        $this->relationFieldCache[$table] = $fields;
        return $fields;
    }

    /**
     * Tatsächlich vorhandene DB-Spalten einer Tabelle (gecacht).
     *
     * @return array<string, true> Spaltenname → true
     */
    private function knownColumns(string $table): array
    {
        if (isset($this->knownColumnsCache[$table])) {
            return $this->knownColumnsCache[$table];
        }
        try {
            $schemaManager = $this->connectionPool
                ->getConnectionForTable($table)
                ->createSchemaManager();
            $map = [];
            foreach ($schemaManager->introspectTable($table)->getColumns() as $col) {
                $map[$col->getName()] = true;
            }
            $this->knownColumnsCache[$table] = $map;
        } catch (\Exception $e) {
            $this->knownColumnsCache[$table] = [];
        }
        return $this->knownColumnsCache[$table];
    }
}
