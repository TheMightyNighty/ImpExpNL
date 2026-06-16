<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Robbi\ImpExpNL\Service\ConfigurationService;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;

class ConfigurationServiceTest extends TestCase
{
    private function createSubject(array $config): ConfigurationService
    {
        $yaml = $this->createMock(YamlFileLoader::class);
        $yaml->method('load')->willReturn($config);

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);

        return new ConfigurationService($yaml, $packageManager, new NullLogger());
    }

    #[Test]
    public function linkRewriteFieldsFallBackWhenUnconfigured(): void
    {
        $subject = $this->createSubject([]);
        self::assertSame(['bodytext', 'pi_flexform'], $subject->getLinkRewriteFields());
    }

    #[Test]
    public function linkRewriteFieldsAreReadFromConfig(): void
    {
        $subject = $this->createSubject(['import' => ['link_rewrite' => ['fields' => ['bodytext', 'tx_gsb_accordion_text']]]]);
        self::assertSame(['bodytext', 'tx_gsb_accordion_text'], $subject->getLinkRewriteFields());
    }

    #[Test]
    public function fileReferencesFlagIsRead(): void
    {
        $subject = $this->createSubject(['export' => ['include' => ['file_references' => true]]]);
        self::assertTrue($subject->isFileReferencesEnabled('export'));
        self::assertFalse($subject->isFileReferencesEnabled('import'));
    }

    #[Test]
    public function lockStaleSecondsUsesDefaultWhenUnset(): void
    {
        self::assertSame(3600, $this->createSubject([])->getLockStaleSeconds());
        self::assertSame(120, $this->createSubject([])->getLockStaleSeconds(120));
    }

    #[Test]
    public function lockStaleSecondsAreReadFromConfig(): void
    {
        $subject = $this->createSubject(['import' => ['lock_stale_seconds' => 900]]);
        self::assertSame(900, $subject->getLockStaleSeconds());
    }

    #[Test]
    public function registeredTablesComeFromMainConfig(): void
    {
        $subject = $this->createSubject(['impexpnl' => ['tables' => ['sys_redirect' => ['type' => 'record']]]]);
        self::assertArrayHasKey('sys_redirect', $subject->getRegisteredTables());
    }
}
