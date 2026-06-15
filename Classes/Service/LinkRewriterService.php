<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class LinkRewriterService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly YamlFileLoader $yamlFileLoader
    ) {}

    /**
     * Schreibt t3://page-Links in allen konfigurierten Feldern auf die neuen UIDs um.
     */
    public function rewriteLinks(array $uidMap, int $workspaceId): void
    {
        if (empty($uidMap['pages']) || empty($uidMap['tt_content'])) {
            return;
        }

        // 1. YAML-Konfiguration sicher laden
        $fieldsToSearch = ['bodytext', 'pi_flexform']; // Fallback
        try {
            $config = $this->yamlFileLoader->load('EXT:robbi_copy/robbi_copy.yaml');
            if (!empty($config['import']['link_rewrite']['fields'])) {
                $fieldsToSearch = $config['import']['link_rewrite']['fields'];
            }
        } catch (\Exception $e) {
            // Bei Fehlern greift der Fallback
        }

        $dataMap = [];

        // 2. Inhalte workspace-konform auslesen und Links anpassen
        // v15-ready: Direkter DB-Zugriff statt BackendUtility::getRecordWSOL()
        foreach ($uidMap['tt_content'] as $oldUid => $newUid) {
            $row = $this->fetchRecord('tt_content', (int)$newUid);

            if (!$row) {
                continue;
            }

            $updateData = [];
            foreach ($fieldsToSearch as $field) {
                if (empty($row[$field]) || !is_string($row[$field])) {
                    continue;
                }

                $newText = preg_replace_callback(
                    '/t3:\/\/page\?uid=(\d+)/',
                    function ($matches) use ($uidMap) {
                        $linkedOldUid = (int)$matches[1];
                        if (isset($uidMap['pages'][$linkedOldUid])) {
                            return 't3://page?uid=' . $uidMap['pages'][$linkedOldUid];
                        }
                        return $matches[0];
                    },
                    $row[$field]
                );

                if ($newText !== $row[$field]) {
                    $updateData[$field] = $newText;
                }
            }

            if (!empty($updateData)) {
                $dataMap['tt_content'][$newUid] = $updateData;
            }
        }

        // 3. Sicheres Speichern über den DataHandler
        if (!empty($dataMap)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
        }
    }

    /**
     * Lädt einen Record direkt aus der Datenbank.
     * Ersetzt BackendUtility::getRecordWSOL() für v15-Kompatibilität.
     */
    private function fetchRecord(string $table, int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
}
