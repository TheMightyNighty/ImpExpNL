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

namespace Robbi\ImpExpNL\Event;

final class ModifyExportDataEvent
{
    public function __construct(
        private array $exportData
    ) {}

    public function getExportData(): array
    {
        return $this->exportData;
    }

    public function setExportData(array $exportData): void
    {
        $this->exportData = $exportData;
    }
}
