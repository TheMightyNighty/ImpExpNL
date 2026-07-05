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

namespace Robbi\ImpExpNL\Tests\Functional\Transport;

use PHPUnit\Framework\Attributes\Test;
use Robbi\ImpExpNL\Exception\ConfigException;
use Robbi\ImpExpNL\Service\ExportService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Ausgehender Transport `push`: signiertes Bundle wird atomar in die Outbox abgelegt.
 */
class PushTransportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function pushPlacesSignedBundleAtomicallyInOutbox(): void
    {
        $dir = $this->instancePath . '/var/push';
        $outbox = $this->instancePath . '/var/outbox';
        GeneralUtility::mkdir_deep($dir);
        GeneralUtility::mkdir_deep($outbox);

        $result = $this->get(ExportService::class)->runExport(1, $dir . '/export.json', [
            'transport' => 'push',
            'transportConfig' => ['outbox' => $outbox],
        ]);

        self::assertSame('push', $result['type']);
        self::assertSame($outbox . '/export.tar.gz', $result['artifact']);
        self::assertFileExists($outbox . '/export.tar.gz');

        // Keine .part-Reste im Staging (atomare Ablage abgeschlossen).
        self::assertSame([], glob($outbox . '/.incoming/*.part') ?: []);

        // Das abgelegte Paket ist ein gültiges Bundle mit der Export-Datei.
        $readCopy = $dir . '/verify_' . bin2hex(random_bytes(4)) . '.tar.gz';
        copy($outbox . '/export.tar.gz', $readCopy);
        $phar = new \PharData($readCopy);
        self::assertTrue(isset($phar['export.json']), 'Export-Datei fehlt im abgelegten Bundle');
    }

    #[Test]
    public function missingOutboxThrowsConfigException(): void
    {
        $dir = $this->instancePath . '/var/push_noout';
        GeneralUtility::mkdir_deep($dir);

        $this->expectException(ConfigException::class);
        $this->get(ExportService::class)->runExport(1, $dir . '/export.json', [
            'transport' => 'push',
            'transportConfig' => ['outbox' => $this->instancePath . '/var/does_not_exist'],
        ]);
    }
}
