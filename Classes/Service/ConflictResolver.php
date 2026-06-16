<?php

declare(strict_types=1);

namespace Robbi\ImpExpNL\Service;

use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Domain\SystemFields;

/**
 * Vergleich von Import- und Zielrecords: Gleichheit, Konflikt-Erkennung und
 * Feld-Diff für die Ausgabe.
 */
class ConflictResolver
{
    /**
     * Felder, die beim Vergleich grundsätzlich ignoriert werden.
     *
     * @var string[]
     */
    private array $ignoredFields;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        $this->ignoredFields = array_merge(SystemFields::EXCLUDED, ['tx_impexpnl_remote_uid', 'sorting']);
    }

    public function isRecordIdentical(array $import, array $existing): bool
    {
        foreach ($import as $field => $value) {
            if (in_array($field, $this->ignoredFields, true)) {
                continue;
            }
            if (!array_key_exists($field, $existing)) {
                continue;
            }
            if ((string)$value !== (string)$existing[$field]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Liefert eine Meldung, wenn der Zielrecord neuer ist als der Export
     * (lokal nach dem Export bearbeitet), sonst null.
     */
    public function detectConflict(array $importRecord, array $existingRecord): ?string
    {
        $exportTs = (int)($importRecord['tstamp'] ?? 0);
        $localTs = (int)($existingRecord['tstamp'] ?? 0);
        if ($localTs > $exportTs && !$this->isRecordIdentical($importRecord, $existingRecord)) {
            $label = $existingRecord['title'] ?? $existingRecord['header'] ?? '';
            return sprintf(
                'uid=%d ("%s"): Lokal %s, Export %s',
                $existingRecord['uid'],
                $label,
                date('d.m.Y H:i', $localTs),
                date('d.m.Y H:i', $exportTs)
            );
        }
        return null;
    }

    public function logFieldDiff(array $import, array $existing, string $table): void
    {
        $diffs = [];
        foreach ($import as $field => $value) {
            if (in_array($field, $this->ignoredFields, true)) {
                continue;
            }
            if (!array_key_exists($field, $existing)) {
                continue;
            }
            if ((string)$value !== (string)$existing[$field]) {
                $oldVal = mb_substr((string)$existing[$field], 0, 80);
                $newVal = mb_substr((string)$value, 0, 80);
                $diffs[] = "    $field: \"$oldVal\" → \"$newVal\"";
            }
        }
        if (!empty($diffs)) {
            $this->logger->info("Feld-Diff $table uid=" . ($existing['uid'] ?? '?') . ":\n" . implode("\n", $diffs));
        }
    }
}
