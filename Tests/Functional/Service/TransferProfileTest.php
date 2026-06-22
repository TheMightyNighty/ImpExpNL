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
use Robbi\ImpExpNL\Domain\ConflictStrategy;
use Robbi\ImpExpNL\Exception\ConfigException;
use Robbi\ImpExpNL\Service\ExportService;
use Robbi\ImpExpNL\Service\ImportService;
use Robbi\ImpExpNL\Service\TransferProfileService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Transfer-Profile aus der YAML-Config: Auflösung (Transport + Guardrails) und
 * Durchsetzung der Umgebungs-Bindung (allowedContext) beim Import.
 * Functional-Tests laufen im TYPO3-Context „Testing".
 */
class TransferProfileTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    #[Test]
    public function resolvesProfileWithTransportAndGuardrails(): void
    {
        $profile = $this->get(TransferProfileService::class)->resolve('prod_to_dev');

        self::assertSame('external', $profile->transport['type']);
        self::assertSame(ConflictStrategy::Overwrite, $profile->conflictStrategy);
        self::assertContains('Testing', $profile->allowedContext);
    }

    #[Test]
    public function bundleProfileResolvesBundleTransport(): void
    {
        $profile = $this->get(TransferProfileService::class)->resolve('stage_to_prod_release');
        self::assertSame('bundle', $profile->transport['type']);
        self::assertSame(ConflictStrategy::Abort, $profile->conflictStrategy);
    }

    #[Test]
    public function unknownProfileThrowsConfigException(): void
    {
        $this->expectException(ConfigException::class);
        $this->get(TransferProfileService::class)->resolve('gibt_es_nicht');
    }

    #[Test]
    public function contextBindingAllowsMatchingContext(): void
    {
        // prod_to_dev erlaubt [Development, Testing] -> im Test-Context erlaubt.
        $service = $this->get(TransferProfileService::class);
        $service->assertContextAllowed($service->resolve('prod_to_dev'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function contextBindingBlocksWrongContext(): void
    {
        // stage_to_prod_release erlaubt nur [Production] -> im Test-Context (Testing) verboten.
        $service = $this->get(TransferProfileService::class);
        $this->expectException(ConfigException::class);
        $service->assertContextAllowed($service->resolve('stage_to_prod_release'));
    }

    #[Test]
    public function importWithProductionProfileIsRefusedInTestContext(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $file = $this->instancePath . '/var/tp.json';
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->get(ExportService::class)->exportTree(1));

        try {
            $this->get(ImportService::class)->runImport($file, 0, ['transferProfile' => 'stage_to_prod_release']);
            self::fail('Import hätte wegen Kontext-Bindung abbrechen müssen.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('Production', $e->getMessage());
        }
    }
}
