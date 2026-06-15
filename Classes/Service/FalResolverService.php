<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class FalResolverService
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Importiert FAL-Referenzen und löst Dateien über den Dateipfad auf.
     */
    public function importReferences(array $exportedReferences, array &$uidMap, DataHandler $dataHandler, array $options): void
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
            $dataMap['sys_file_reference'][$tempRefId] = [
                'uid_local' => $liveSysFileUid,
                'uid_foreign' => $newForeignUid,
                'tablenames' => $tableName,
                'fieldname' => $ref['fieldname'],
                'pid' => $uidMap['pages'][$ref['pid']] ?? $ref['pid'],
            ];
        }

        if (!empty($cmdMap['sys_file_reference'])) {
            $dataHandler->start([], $cmdMap);
            $dataHandler->process_cmdmap();
        }

        if (!empty($dataMap['sys_file_reference'])) {
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
        }
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
