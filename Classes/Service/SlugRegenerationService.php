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
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regeneriert die `slug`-Felder importierter Seiten für die Ziel-Site, damit beim
 * Einspielen in einen bestehenden Baum keine Slug-Kollisionen entstehen und die
 * Slugs zur Site-Konfiguration des Zielsystems passen.
 *
 * Aus {@see ImportService} extrahiert. Die Slug-Updates laufen gebündelt in einer
 * DB-Transaktion; die Anzahl der DataHandler-Fehler wird zurückgegeben.
 */
class SlugRegenerationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string, array<int,int>> $uidMap Quell-UID => Ziel-UID je Tabelle
     * @return int Anzahl der DataHandler-Fehler bei den Slug-Updates
     */
    public function regenerate(array $uidMap): int
    {
        if (empty($uidMap['pages'])) {
            return 0;
        }

        try {
            $slugConfig = [];
            if ($this->tcaSchemaFactory->has('pages') && $this->tcaSchemaFactory->get('pages')->hasField('slug')) {
                $slugConfig = $this->tcaSchemaFactory->get('pages')->getField('slug')->getConfiguration();
            }
            $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $slugConfig);
        } catch (\Exception $e) {
            $this->logger->warning('Slug-Regenerierung übersprungen, SlugHelper nicht verfügbar: ' . $e->getMessage());
            return 0;
        }

        $datamap = [];
        $newUids = array_map('intval', array_values($uidMap['pages']));

        foreach (array_chunk($newUids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $pages = $qb->select('*')->from('pages')
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAllAssociative();

            foreach ($pages as $page) {
                try {
                    $newSlug = $this->generateUniqueSlug($slugHelper, $slugConfig, $page);
                    if ($newSlug !== ($page['slug'] ?? '')) {
                        $datamap['pages'][(int)$page['uid']] = ['slug' => $newSlug];
                    }
                } catch (\Exception $e) {
                    // Slug konnte nicht erzeugt werden; bestehenden Wert beibehalten.
                }
            }
        }

        if (empty($datamap)) {
            return 0;
        }

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start($datamap, []);
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->beginTransaction();
        try {
            $dh->process_datamap();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $errors = count($dh->errorLog);
        foreach ($dh->errorLog as $error) {
            $this->logger->error("DataHandler (slug): $error");
        }
        $this->logger->info('Slugs angepasst', ['count' => count($datamap['pages'])]);
        return $errors;
    }

    /**
     * Erzeugt einen für die Ziel-Site eindeutigen Slug.
     *
     * @param array<string, mixed> $slugConfig
     * @param array<string, mixed> $page
     */
    private function generateUniqueSlug(SlugHelper $slugHelper, array $slugConfig, array $page): string
    {
        $pid = (int)$page['pid'];
        $slug = $slugHelper->generate($page, $pid);

        $state = RecordStateFactory::forName('pages')->fromArray($page, $pid, (int)$page['uid']);

        $eval = (string)($slugConfig['eval'] ?? '');
        if (str_contains($eval, 'uniqueInSite')) {
            return $slugHelper->buildSlugForUniqueInSite($slug, $state);
        }
        if (str_contains($eval, 'uniqueInPid')) {
            return $slugHelper->buildSlugForUniqueInPid($slug, $state);
        }
        if (str_contains($eval, 'uniqueInTable')) {
            return $slugHelper->buildSlugForUniqueInTable($slug, $state);
        }
        return $slug;
    }
}
