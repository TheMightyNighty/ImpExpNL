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

namespace Robbi\ImpExpNL\Tests\Functional\Profile;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Contract des Kern-Profils „core": Seiten + Inhalte mit t3://-Link-Rewriting
 * und sys_category-Zuordnung (MM). Dieses Profil gilt als unterstützt, solange
 * alle Klauseln dieses Tests grün sind.
 */
class CoreProfileContractTest extends AbstractProfileContract
{
    protected function profileFixtures(): array
    {
        return [
            __DIR__ . '/../Fixtures/pages.csv',
            __DIR__ . '/../Fixtures/tt_content.csv',
            __DIR__ . '/../Fixtures/sys_category.csv',
            __DIR__ . '/../Fixtures/sys_category_record_mm.csv',
        ];
    }

    protected function expectedSourceUids(): array
    {
        // Versteckte Seite uid=5 wird vom Default-Export ausgeschlossen.
        return [
            'pages' => [1, 2, 3, 4],
            'tt_content' => [10, 11, 12, 13],
        ];
    }

    protected function verifyLinkRewrite(): void
    {
        // Content 10 enthält "t3://page?uid=3" -> muss auf die neue UID von Seite 3 zeigen.
        $newContent = $this->resolveTargetUid('tt_content', 10);
        $newPage = $this->resolveTargetUid('pages', 3);
        self::assertNotNull($newContent);
        self::assertNotNull($newPage);

        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $bodytext = (string)$qb->select('bodytext')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newContent, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();

        self::assertStringContainsString('t3://page?uid=' . $newPage, $bodytext, 'Link wurde nicht auf neue Seiten-UID umgeschrieben');
        self::assertStringNotContainsString('t3://page?uid=3', $bodytext, 'Alter Link-Ziel-UID blieb erhalten');
    }

    protected function verifyCategoryMapping(): void
    {
        // Kategorie "Digitalisierung" (uid_local=2) muss am neuen Content 10 hängen.
        $newContent = $this->resolveTargetUid('tt_content', 10);
        self::assertNotNull($newContent);

        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        $relation = $qb->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newContent, Connection::PARAM_INT)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content'))
            )
            ->executeQuery()->fetchAssociative();

        self::assertNotFalse($relation, 'Kategorie-Zuordnung fehlt am importierten Content');
        self::assertSame(2, (int)$relation['uid_local'], 'Kategorie wurde nicht korrekt aufgelöst');
    }
}
