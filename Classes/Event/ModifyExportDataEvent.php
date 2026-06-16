<?php

declare(strict_types=1);

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
