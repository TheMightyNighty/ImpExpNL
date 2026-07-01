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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Zweites Ziel-Extension-Profil (EXT:friendsoftypo3/tt-address): belegt, dass Record-Profil
 * UND Kategorie-MM generisch – nicht news-spezifisch – funktionieren. Neben den vier
 * Pflichtklauseln wird hier auch die Kategorie-Relation (sys_category über MM) geprüft.
 */
class TtAddressProfileContractTest extends AbstractProfileContract
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/tt_address',
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!ExtensionManagementUtility::isLoaded('tt_address')) {
            self::markTestSkipped('EXT:tt_address nicht installiert.');
        }
    }

    protected function profileFixtures(): array
    {
        return [
            __DIR__ . '/../Fixtures/pages.csv',
            __DIR__ . '/../Fixtures/tt_address.csv',
            __DIR__ . '/../Fixtures/sys_category.csv',
        ];
    }

    /**
     * Kategorie-Relation VOR dem Export: Adresse 1 ↔ Kategorie 2 ("Themen > Digitalisierung").
     */
    protected function prepareProfileState(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_category_record_mm')->insert(
            'sys_category_record_mm',
            [
                'uid_local' => 2,
                'uid_foreign' => 1,
                'tablenames' => 'tt_address',
                'fieldname' => 'categories',
                'sorting' => 1,
                'sorting_foreign' => 1,
            ]
        );
    }

    protected function expectedSourceUids(): array
    {
        return [
            'pages' => [1, 2, 3, 4],
            'tt_address' => [1, 2],
        ];
    }

    /**
     * Nach dem Import muss die Kategorie-Relation der Adresse generisch (registrierte
     * Record-Tabelle!) auf die neue Adress-UID und die pfad-gematchte Kategorie zeigen.
     */
    protected function verifyCategoryMapping(): void
    {
        $newAddress = (int)$this->resolveTargetUid('tt_address', 1);
        self::assertGreaterThan(0, $newAddress);

        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        $categoryUid = $qb->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_address')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($newAddress, Connection::PARAM_INT))
            )
            ->executeQuery()->fetchOne();

        self::assertNotFalse($categoryUid, 'Kategorie-MM der Adresse wurde nicht importiert');

        // Pfad-Matching: Quelle=Ziel im Functional-Test → dieselbe Kategorie "Digitalisierung" (uid 2).
        $qb2 = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category');
        $qb2->getRestrictions()->removeAll();
        $title = $qb2->select('title')->from('sys_category')
            ->where($qb2->expr()->eq('uid', $qb2->createNamedParameter((int)$categoryUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame('Digitalisierung', $title, 'Kategorie der Adresse falsch aufgelöst');
    }
}
