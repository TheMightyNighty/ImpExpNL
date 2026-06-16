<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Service;

use Robbi\ImpExpNL\Domain\PageLinkRewriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class LinkRewriterService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configurationService
    ) {}

    /**
     * Schreibt t3://page-Links in allen konfigurierten Feldern auf die neuen UIDs um.
     */
    public function rewriteLinks(array $uidMap, int $workspaceId): void
    {
        // Umschreibungsziele sind Seiten-UIDs; ohne Seiten-Mapping gibt es nichts zu tun.
        if (empty($uidMap['pages'])) {
            return;
        }

        $fieldsToSearch = $this->configurationService->getLinkRewriteFields();
        $dataMap = [];

        // Felder, die in einer Tabelle nicht existieren, fallen über die empty()-Prüfung heraus.
        foreach (['pages', 'tt_content'] as $table) {
            foreach ($uidMap[$table] ?? [] as $oldUid => $newUid) {
                $row = $this->fetchRecord($table, (int)$newUid, $workspaceId);
                if (!$row) {
                    continue;
                }

                $updateData = [];
                foreach ($fieldsToSearch as $field) {
                    if (empty($row[$field]) || !is_string($row[$field])) {
                        continue;
                    }

                    $newText = PageLinkRewriter::rewrite($row[$field], $uidMap['pages']);

                    if ($newText !== $row[$field]) {
                        $updateData[$field] = $newText;
                    }
                }

                if (!empty($updateData)) {
                    $dataMap[$table][$newUid] = $updateData;
                }
            }
        }

        if (!empty($dataMap)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
        }
    }

    /**
     * Liest den zu bearbeitenden Record. Bei einem Workspace-Import wird die
     * Workspace-Version bevorzugt, da sie den importierten Inhalt trägt – die
     * Live-Zeile ist dort nur ein Platzhalter.
     */
    private function fetchRecord(string $table, int $uid, int $workspaceId): ?array
    {
        if ($workspaceId > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $version = $qb->select('*')
                ->from($table)
                ->where(
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                    $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($workspaceId, Connection::PARAM_INT))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if ($version) {
                return $version;
            }
        }

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
