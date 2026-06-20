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

namespace Robbi\ImpExpNL\Tests\Unit;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validiert die ausgelieferte YAML-Konfiguration gegen das JSON-Schema
 * (Configuration/Schema/ImpExpNL.schema.json) – stellt sicher, dass Schema und
 * tatsächliche Konfiguration zusammenpassen (IDE-Autocomplete + CI-Validierung).
 */
class ConfigurationSchemaTest extends TestCase
{
    private function schema(): object
    {
        $path = dirname(__DIR__, 2) . '/Configuration/Schema/ImpExpNL.schema.json';
        return json_decode((string)file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }

    private function toJson(array $data): mixed
    {
        return json_decode((string)json_encode($data), false, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function schemaFileIsValidJson(): void
    {
        self::assertIsObject($this->schema());
    }

    #[Test]
    public function shippedConfigMatchesSchema(): void
    {
        $data = Yaml::parseFile(dirname(__DIR__, 2) . '/imp_exp_nl.yaml');
        $result = (new Validator())->validate($this->toJson((array)$data), $this->schema());
        self::assertTrue($result->isValid(), 'imp_exp_nl.yaml verletzt das JSON-Schema');
    }

    #[Test]
    public function recordTableWithoutPidFieldIsRejected(): void
    {
        $data = ['impexpnl' => ['tables' => ['tx_demo' => ['type' => 'record']]]];
        $result = (new Validator())->validate($this->toJson($data), $this->schema());
        self::assertFalse($result->isValid());
    }

    #[Test]
    public function invalidTableTypeIsRejected(): void
    {
        $data = ['impexpnl' => ['tables' => ['tx_demo' => ['type' => 'bogus']]]];
        $result = (new Validator())->validate($this->toJson($data), $this->schema());
        self::assertFalse($result->isValid());
    }
}
