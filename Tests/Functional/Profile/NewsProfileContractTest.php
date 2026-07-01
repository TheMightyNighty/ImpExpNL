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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Contract des Ziel-Extension-Profils EXT:georgringer/news: die registrierte Record-Tabelle
 * tx_news_domain_model_news muss exportiert, beim Import auf die neue Seite gemappt, beim
 * Delta nicht dupliziert (Records-Zählung) und beim Rollback wieder entfernt werden.
 *
 * Geprüft werden die vier Pflichtklauseln des Harness. Kategorie-Relationen (MM) für
 * registrierte Record-Tabellen werden derzeit NICHT übertragen (eigene Registry-Grenze,
 * s. ROADMAP) und daher hier bewusst nicht gefordert.
 */
class NewsProfileContractTest extends AbstractProfileContract
{
    protected array $coreExtensionsToLoad = ['extbase', 'fluid'];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/news',
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!ExtensionManagementUtility::isLoaded('news')) {
            self::markTestSkipped('EXT:news nicht installiert.');
        }
    }

    protected function profileFixtures(): array
    {
        return [
            __DIR__ . '/../Fixtures/pages.csv',
            __DIR__ . '/../Fixtures/tx_news_domain_model_news.csv',
        ];
    }

    protected function expectedSourceUids(): array
    {
        return [
            'pages' => [1, 2, 3, 4],
            'tx_news_domain_model_news' => [1, 2, 3],
        ];
    }
}
