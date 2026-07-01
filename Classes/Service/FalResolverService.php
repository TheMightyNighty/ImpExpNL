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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class FalResolverService
{
    /** Referenz-Metadaten, die beim Import erhalten bleiben müssen. */
    private const METADATA_FIELDS = ['title', 'description', 'alternative', 'link', 'crop', 'sorting_foreign'];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Importiert FAL-Referenzen und löst Dateien über den Dateipfad auf.
     *
     * @return int Anzahl der DataHandler-Fehler
     */
    public function importReferences(array $exportedReferences, array &$uidMap, DataHandler $dataHandler, array $options): int
    {
        $dataMap = ['sys_file_reference' => []];
        $cmdMap = ['sys_file_reference' => []];
        $clearedContentUids = [];
        $counterTargets = [];
        $uidMap['sys_file'] = [];

        foreach ($exportedReferences as $ref) {
            $tableName = $ref['tablenames'];
            $oldForeignUid = $ref['uid_foreign'];

            if (!isset($uidMap[$tableName][$oldForeignUid])) {
                continue;
            }

            $newForeignUid = $uidMap[$tableName][$oldForeignUid];
            // Storage der Quelle bevorzugen (Multi-Storage), sonst konfigurierter Default.
            $storageId = (int)($ref['storage'] ?? 0) ?: (int)($options['storageId'] ?? 1);

            $identifier = $ref['identifier'] ?? '';
            if (empty($identifier)) {
                continue;
            }

            if (class_exists(\Normalizer::class)) {
                $identifier = \Normalizer::normalize($identifier, \Normalizer::FORM_C);
            }

            $clearKey = $tableName . '_' . $newForeignUid;
            if (!empty($options['upsert']) && !isset($clearedContentUids[$clearKey])) {
                $this->deleteExistingReferences($newForeignUid, $tableName, $cmdMap, $counterTargets);
                $clearedContentUids[$clearKey] = true;
            }

            $liveSysFileUid = null;
            try {
                $storage = $this->resourceFactory->getStorageObject($storageId);
                if ($storage->hasFile($identifier)) {
                    $fileObject = $storage->getFile($identifier);
                    if ($fileObject instanceof File) {
                        $liveSysFileUid = $fileObject->getUid();
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FAL-Referenz übersprungen, Datei nicht auflösbar.', [
                    'identifier' => $identifier,
                    'storageId' => $storageId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($liveSysFileUid === null) {
                $this->logger->warning('FAL-Referenz übersprungen, Datei auf Zielsystem nicht vorhanden.', [
                    'identifier' => $identifier,
                    'storageId' => $storageId,
                ]);
                continue;
            }

            $uidMap['sys_file'][$ref['uid_local']] = $liveSysFileUid;

            $tempRefId = 'NEW_REF_' . $ref['uid'];
            $newRef = [
                'uid_local' => $liveSysFileUid,
                'uid_foreign' => $newForeignUid,
                'tablenames' => $tableName,
                'fieldname' => $ref['fieldname'],
                'pid' => $uidMap['pages'][$ref['pid']] ?? $ref['pid'],
            ];
            // Referenz-Metadaten mitnehmen (sonst gehen Crop, Alt-Text, Titel etc. verloren).
            foreach (self::METADATA_FIELDS as $metaField) {
                if (array_key_exists($metaField, $ref) && $ref[$metaField] !== null) {
                    $newRef[$metaField] = $ref[$metaField];
                }
            }
            $dataMap['sys_file_reference'][$tempRefId] = $newRef;

            // Eltern-Record/-Feld merken, dessen denormalisierter Zähler nachzuziehen ist.
            $counterTargets[$tableName][$newForeignUid][$ref['fieldname']] = true;
        }

        $errors = 0;

        if (!empty($cmdMap['sys_file_reference'])) {
            $dataHandler->start([], $cmdMap);
            $dataHandler->process_cmdmap();
            $errors += $this->logErrors($dataHandler);
        }

        if (!empty($dataMap['sys_file_reference'])) {
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
            $errors += $this->logErrors($dataHandler);
        }

        // Der DataHandler legt die sys_file_reference-Records standalone an und pflegt dabei
        // NICHT den denormalisierten Zähler am Eltern-Feld (z. B. tt_content.image). Den
        // ziehen wir hier verifizierend nach: pro Eltern-Feld die echte Anzahl lebender
        // Referenzen zählen und schreiben. Nur ein Cache-Wert – die Referenzen selbst hat
        // der DataHandler bereits korrekt geschrieben. Erfasst auch die Drift nach unten über
        // den Delete-Pfad (Upsert entfernt Referenzen, ohne dass neue entstehen), bis 0.
        $this->updateReferenceCounters($counterTargets);

        return $errors;
    }

    /**
     * Setzt den denormalisierten FAL-Zähler am Eltern-Feld auf die tatsächliche Anzahl
     * lebender sys_file_reference-Einträge (idempotent, korrekt auch bei Delta/Upsert).
     *
     * @param array<string, array<int, array<string, true>>> $targets table => uid => field => true
     */
    private function updateReferenceCounters(array $targets): void
    {
        foreach ($targets as $tableName => $byUid) {
            $connection = $this->connectionPool->getConnectionForTable($tableName);
            foreach ($byUid as $foreignUid => $fields) {
                foreach (array_keys($fields) as $fieldName) {
                    $count = $this->countLiveReferences((int)$foreignUid, $tableName, (string)$fieldName);
                    $connection->update($tableName, [$fieldName => $count], ['uid' => $foreignUid]);
                }
            }
        }
    }

    private function countLiveReferences(int $foreignUid, string $tableName, string $fieldName): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($foreignUid, Connection::PARAM_INT)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($tableName)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldName)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();
    }

    private function logErrors(DataHandler $dataHandler): int
    {
        if (empty($dataHandler->errorLog)) {
            return 0;
        }
        foreach ($dataHandler->errorLog as $error) {
            $this->logger->error('DataHandler (sys_file_reference): ' . $error);
        }
        return count($dataHandler->errorLog);
    }

    /**
     * @param array<string, array<int, array<string, true>>> $counterTargets Delete-Pfad-Felder
     *        werden mitregistriert, damit der Zähler-Nachpass auch eine Drift nach unten bis 0 korrigiert.
     */
    private function deleteExistingReferences(int $foreignUid, string $tableName, array &$cmdMap, array &$counterTargets): void
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_reference');

        $existingRefs = $queryBuilder->select('uid', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($foreignUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tableName))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($existingRefs as $existingRef) {
            $cmdMap['sys_file_reference'][$existingRef['uid']]['delete'] = 1;
            $counterTargets[$tableName][$foreignUid][(string)$existingRef['fieldname']] = true;
        }
    }
}
