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

namespace Robbi\ImpExpNL\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\UrlRewriteService;
use Robbi\ImpExpNL\Tests\Functional\UidMapTestTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * URL-Rewrite: absolute Domain-URLs werden feldbezogen ersetzt (literal/regex),
 * getrennt vom t3://page-Link-Rewriter.
 */
class UrlRewriteTest extends FunctionalTestCase
{
    use UidMapTestTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function applyRulesHandlesLiteralAndRegex(): void
    {
        $service = $this->get(UrlRewriteService::class);

        self::assertSame(
            'a https://dev.example.com/x b',
            $service->applyRules('a https://prod.example.com/x b', [
                ['search' => 'https://prod.example.com', 'replace' => 'https://dev.example.com'],
            ])
        );

        self::assertSame(
            'go to https://dev.example.com/p',
            $service->applyRules('go to https://stage.example.com/p', [
                ['search' => '#https://(stage|prod)\.example\.com#', 'replace' => 'https://dev.example.com', 'regex' => true],
            ])
        );
    }

    #[Test]
    public function invalidRegexIsSkippedWithoutError(): void
    {
        $service = $this->get(UrlRewriteService::class);
        // Ungültiges Muster -> Wert bleibt unverändert, kein Fehler.
        self::assertSame('unchanged', $service->applyRules('unchanged', [
            ['search' => '#[invalid', 'replace' => 'x', 'regex' => true],
        ]));
    }

    #[Test]
    public function rewriteUpdatesImportedRecordField(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $file = $this->instancePath . '/var/urlrewrite.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->get(ExportService::class)->exportTree(1));
        $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);

        $newContent = (int)$this->resolveTargetUid('tt_content', 10);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => 'Mehr unter https://prod.example.com/info'], ['uid' => $newContent]);

        $changed = $this->get(UrlRewriteService::class)->rewrite(
            ['pages' => [], 'tt_content' => [10 => $newContent]],
            [['search' => 'https://prod.example.com', 'replace' => 'https://dev.example.com']],
            ['bodytext'],
            0
        );

        self::assertSame(1, $changed);
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $bodytext = (string)$qb->select('bodytext')->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($newContent, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();

        self::assertStringContainsString('https://dev.example.com/info', $bodytext);
        self::assertStringNotContainsString('prod.example.com', $bodytext);
    }
}
