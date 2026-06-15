<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Event;

final class ModifyImportDataEvent
{
    public function __construct(
        private array $importData,
        private array $uidMap
    ) {}

    public function getImportData(): array
    {
        return $this->importData;
    }

    public function setImportData(array $importData): void
    {
        $this->importData = $importData;
    }

    public function getUidMap(): array
    {
        return $this->uidMap;
    }
}
