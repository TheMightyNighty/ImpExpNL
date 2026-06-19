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
use TYPO3\CMS\Core\Resource\StorageRepository;

class FalResolverService
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
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
                $this->deleteExistingReferences($newForeignUid, $tableName, $cmdMap);
                $clearedContentUids[$clearKey] = true;
            }

            $liveSysFileUid = null;
            try {
                $storage = $this->storageRepository->getStorageObject($storageId);
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
            $dataMap['sys_file_reference'][$tempRefId] = [
                'uid_local' => $liveSysFileUid,
                'uid_foreign' => $newForeignUid,
                'tablenames' => $tableName,
                'fieldname' => $ref['fieldname'],
                'pid' => $uidMap['pages'][$ref['pid']] ?? $ref['pid'],
            ];
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

        return $errors;
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

    private function deleteExistingReferences(int $foreignUid, string $tableName, array &$cmdMap): void
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_reference');

        $existingRefs = $queryBuilder->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($foreignUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tableName))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($existingRefs as $existingRef) {
            $cmdMap['sys_file_reference'][$existingRef['uid']]['delete'] = 1;
        }
    }
}
