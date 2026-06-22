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
use Robbi\ImpExpNL\Service\SanitizeService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PII-Sanitizing: anonymisiert konfigurierte Felder beim Export – vor der
 * Prüfsumme, damit personenbezogene Daten Produktion nicht verlassen und der
 * Export integer bleibt.
 */
class SanitizeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function appliesFixedHashAndClearStrategies(): void
    {
        $data = [
            'fe_users' => [
                ['uid' => 1, 'email' => 'alice@prod.example.com', 'username' => 'alice', 'password' => 'secret', 'first_name' => 'Alice'],
            ],
        ];
        $rules = [
            'fe_users' => [
                ['field' => 'email', 'strategy' => 'hash'],
                ['field' => 'password', 'strategy' => 'clear'],
                ['field' => 'first_name', 'strategy' => 'fixed', 'value' => 'Anonym'],
            ],
        ];

        $out = $this->get(SanitizeService::class)->sanitize($data, $rules)['fe_users'][0];

        self::assertSame(sha1('alice@prod.example.com'), $out['email']);
        self::assertSame('', $out['password']);
        self::assertSame('Anonym', $out['first_name']);
        self::assertSame('alice', $out['username'], 'Nicht konfigurierte Felder bleiben unverändert');
    }

    #[Test]
    public function hashIsDeterministic(): void
    {
        $service = $this->get(SanitizeService::class);
        $rules = ['t' => [['field' => 'v', 'strategy' => 'hash']]];
        $a = $service->sanitize(['t' => [['v' => 'x@y.z']]], $rules)['t'][0]['v'];
        $b = $service->sanitize(['t' => [['v' => 'x@y.z']]], $rules)['t'][0]['v'];
        self::assertSame($a, $b);
    }

    #[Test]
    public function exportSanitizesAndStaysIntegrityValid(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $json = $this->get(ExportService::class)->exportTree(1, [
            'sanitize' => [
                'enabled' => true,
                'rules' => ['tt_content' => [['field' => 'header', 'strategy' => 'fixed', 'value' => 'ANONYM']]],
            ],
        ]);
        $data = json_decode($json, true);

        // Alle Inhalts-Header sind anonymisiert.
        foreach ($data['tt_content'] as $row) {
            self::assertSame('ANONYM', $row['header']);
        }

        // Sanitizing lief vor der Prüfsumme -> Import bleibt integer (kein IntegrityException).
        $file = $this->instancePath . '/var/sanitized.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $json);
        $result = $this->get(ImportService::class)->runImport($file, 0, ['workspaceId' => 0]);
        self::assertSame(0, $result['stats']['errors']);
    }
}
