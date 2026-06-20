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
use Robbi\ImpExpNL\Service\ConfigValidationService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional-Test für die Registry-Validierung gegen echtes DB-/TCA-Schema.
 */
class ConfigValidationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/imp_exp_nl',
    ];

    private function subject(): ConfigValidationService
    {
        return $this->get(ConfigValidationService::class);
    }

    /**
     * @param array<int, array{level:string, message:string}> $issues
     */
    private function errorMessages(array $issues): string
    {
        return implode("\n", array_map(
            static fn(array $i): string => $i['level'] . ': ' . $i['message'],
            $issues
        ));
    }

    #[Test]
    public function validConfigurationProducesNoErrors(): void
    {
        $issues = $this->subject()->validate(
            [
                'tt_content' => ['type' => 'record', 'pid_field' => 'pid', 'rewrite_links' => ['bodytext']],
                'sys_category_record_mm' => [
                    'type' => 'mm',
                    'match_field' => 'uid_foreign',
                    'match_tablenames_field' => 'tablenames',
                    'match_tables' => ['pages', 'tt_content'],
                ],
            ],
            ['bodytext', 'pi_flexform']
        );

        $errors = array_filter($issues, static fn(array $i): bool => $i['level'] === 'error');
        self::assertSame([], $errors, 'Unerwartete Fehler: ' . $this->errorMessages($issues));
    }

    #[Test]
    public function detectsUnknownTable(): void
    {
        $issues = $this->subject()->validate(['tx_does_not_exist' => ['type' => 'record', 'pid_field' => 'pid']]);
        self::assertStringContainsString('tx_does_not_exist', $this->errorMessages($issues));
    }

    #[Test]
    public function detectsInvalidType(): void
    {
        $issues = $this->subject()->validate(['tt_content' => ['type' => 'bogus']]);
        self::assertStringContainsString('type', $this->errorMessages($issues));
    }

    #[Test]
    public function detectsMissingRecordField(): void
    {
        $issues = $this->subject()->validate(['tt_content' => ['type' => 'record', 'pid_field' => 'definitiv_kein_feld']]);
        self::assertStringContainsString('definitiv_kein_feld', $this->errorMessages($issues));
    }

    #[Test]
    public function detectsInvalidLinkRewriteField(): void
    {
        $issues = $this->subject()->validate(
            ['tt_content' => ['type' => 'record', 'pid_field' => 'pid']],
            ['bodytext', 'kein_feld_xyz']
        );
        $msg = $this->errorMessages($issues);
        self::assertStringContainsString('kein_feld_xyz', $msg);
        self::assertStringNotContainsString('bodytext', $msg);
    }

    #[Test]
    public function detectsUnknownMatchTableInMm(): void
    {
        $issues = $this->subject()->validate([
            'sys_category_record_mm' => [
                'type' => 'mm',
                'match_field' => 'uid_foreign',
                'match_tables' => ['kein_tca_table'],
            ],
        ]);
        self::assertStringContainsString('kein_tca_table', $this->errorMessages($issues));
    }

    #[Test]
    public function warnsWhenUidRemapMissingForRecord(): void
    {
        $issues = $this->subject()->validate(['tt_content' => ['type' => 'record', 'pid_field' => 'pid']]);

        $warnings = array_filter($issues, static fn(array $i): bool => $i['level'] === 'warning');
        $text = implode("\n", array_map(static fn(array $i): string => $i['message'], $warnings));
        self::assertStringContainsString('uid_remap', $text);
    }

    #[Test]
    public function suggestsClosestFieldOnTypo(): void
    {
        $issues = $this->subject()->validate([
            'tt_content' => ['type' => 'record', 'pid_field' => 'pid', 'uid_remap' => true, 'rewrite_links' => ['bodytex']],
        ]);
        self::assertStringContainsString('Did you mean "bodytext"', $this->errorMessages($issues));
    }

    #[Test]
    public function includesSourceAttribution(): void
    {
        $issues = $this->subject()->validate(
            ['tx_does_not_exist' => ['type' => 'record', 'pid_field' => 'pid']],
            [],
            'tt_content',
            ['tx_does_not_exist' => 'EXT:my_events']
        );
        self::assertStringContainsString('[EXT:my_events]', $this->errorMessages($issues));
    }
}
