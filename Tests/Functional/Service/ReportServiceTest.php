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
use Robbi\ImpExpNL\Service\ImportLogRepository;
use Robbi\ImpExpNL\Service\ReportService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Signierter Transfer-Nachweis: Aufbau aus dem Import-Protokoll, Signatur-
 * Roundtrip (sha256 und HMAC) und Erkennung manipulierter Berichte.
 */
class ReportServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('IMPEXPNL_SIGNING_KEY');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey']);
    }

    protected function tearDown(): void
    {
        putenv('IMPEXPNL_SIGNING_KEY');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey']);
        parent::tearDown();
    }

    private function seedImportLog(string $importId = '20260706_190000_abc123'): void
    {
        $this->get(ImportLogRepository::class)->save(
            $importId,
            0,
            '/tmp/does-not-exist-anymore.jsonl',
            true,
            [
                'pages' => [1 => 11, 2 => 12],
                'tt_content' => [10 => 110],
                '_placed_files' => [5, 6, 7],
            ]
        );
    }

    #[Test]
    public function buildsReportFromImportLog(): void
    {
        $this->seedImportLog();
        $document = $this->get(ReportService::class)->build('20260706_190000_abc123');

        self::assertNotNull($document);
        $report = $document['report'];
        self::assertSame('20260706_190000_abc123', $report['import']['import_id']);
        self::assertTrue($report['import']['delta']);
        self::assertSame(['pages' => 2, 'tt_content' => 1], $report['records']);
        self::assertSame(3, $report['records_total']);
        self::assertSame(3, $report['assets_placed']);
        self::assertFalse($report['source']['available']);
        self::assertStringStartsWith('sha256:', $document['signature']);
    }

    #[Test]
    public function buildWithoutImportIdUsesLatestAndMissingIdReturnsNull(): void
    {
        $service = $this->get(ReportService::class);
        self::assertNull($service->build());
        self::assertNull($service->build('unknown-id'));

        $this->seedImportLog();
        $document = $service->build();
        self::assertNotNull($document);
        self::assertSame('20260706_190000_abc123', $document['report']['import']['import_id']);
    }

    #[Test]
    public function signedReportVerifiesAndTamperedReportFails(): void
    {
        putenv('IMPEXPNL_SIGNING_KEY=report-secret');
        $this->seedImportLog();
        $service = $this->get(ReportService::class);

        $document = $service->build('20260706_190000_abc123');
        self::assertNotNull($document);
        self::assertStringStartsWith('hmac-sha256:', $document['signature']);

        // Roundtrip über JSON, wie es --output/--verify tun.
        $decoded = json_decode((string)json_encode($document), true);
        self::assertTrue($service->verify($decoded));

        // Manipulation (z. B. Record-Zahl geschönt) → Signatur ungültig.
        $decoded['report']['records_total'] = 99999;
        self::assertFalse($service->verify($decoded));

        // Ohne Schlüssel ist der HMAC-Bericht nicht verifizierbar (Downgrade-Sperre).
        putenv('IMPEXPNL_SIGNING_KEY');
        $fresh = json_decode((string)json_encode($document), true);
        self::assertFalse($service->verify($fresh));
    }

    #[Test]
    public function verifyRejectsMalformedDocuments(): void
    {
        $service = $this->get(ReportService::class);
        self::assertFalse($service->verify([]));
        self::assertFalse($service->verify(['report' => 'kein-array', 'signature' => 'sha256:x']));
        self::assertFalse($service->verify(['report' => ['a' => 1], 'signature' => '']));
    }
}
