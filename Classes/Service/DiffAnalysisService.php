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

namespace Robbi\ImpExpNL\Service;

use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Domain\ManifestReader;
use Robbi\ImpExpNL\Domain\SystemFields;

/**
 * Differenzanalyse für den Dry-Run: vergleicht die Importdaten (streamend, batch-weise)
 * mit dem Bestand auf dem Zielsystem und liefert Zählwerte je Tabelle plus Beispielzeilen
 * für die Vorschau. Reine Lese-Operation, keine Schreibzugriffe.
 *
 * Aus {@see ImportService} extrahiert; nutzt denselben {@see ExistingRecordLocator}
 * wie der Import und dieselbe Batch-Größe.
 */
class DiffAnalysisService
{
    private const SKIP_FIELDS = ['sorting', 'sorting_foreign', 'l10n_diffsource'];

    public function __construct(
        private readonly ExistingRecordLocator $existingRecordLocator,
        private readonly ConflictResolver $conflictResolver,
        private readonly ConfigurationService $configurationService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array{pages:array{new:int,changed:int,identical:int}, tt_content:array{new:int,changed:int,identical:int}, samples:array<string,array<string,mixed>>}
     */
    public function analyze(ManifestReader $manifest, bool $verbose): array
    {
        $this->logger->info('=== DIFF ANALYSIS ===');
        $sourceId = $manifest->getSourceId();
        $samples = [];

        $nP = $uP = $iP = 0;
        $this->streamDiffBatches($manifest, 'pages', $sourceId, function (array $p, ?array $existing) use ($verbose, &$nP, &$uP, &$iP, &$samples): void {
            if ($existing === null) {
                $nP++;
                if (!isset($samples['new'])) {
                    $samples['new'] = ['table' => 'pages', 'title' => (string)($p['title'] ?? ''), 'slug' => (string)($p['slug'] ?? '')];
                }
                $this->logger->info('<fg=green>+ NEW</>        | ' . ($p['title'] ?? ''));
            } elseif (!$this->conflictResolver->isRecordIdentical($p, $existing)) {
                $uP++;
                if (!isset($samples['changed'])) {
                    $samples['changed'] = ['table' => 'pages', 'uid' => (int)$p['uid'], 'field' => $this->firstChangedField($p, $existing)];
                }
                $this->logger->info('<fg=yellow>~ CHANGED</>    | ' . ($p['title'] ?? ''));
                if ($verbose) {
                    $this->conflictResolver->logFieldDiff($p, $existing, 'pages');
                }
            } else {
                $iP++;
                $this->logger->info('<fg=blue>= IDENTICAL</>  | ' . ($p['title'] ?? ''));
            }
        });

        $nC = $uC = $iC = 0;
        $this->streamDiffBatches($manifest, 'tt_content', $sourceId, function (array $c, ?array $existing) use ($verbose, &$nC, &$uC, &$iC, &$samples): void {
            if ($existing === null) {
                $nC++;
            } elseif (!$this->conflictResolver->isRecordIdentical($c, $existing)) {
                $uC++;
                // tt_content-Beispiel bevorzugen (zeigt Feld-Diff schöner als pages).
                $samples['changed'] = ['table' => 'tt_content', 'uid' => (int)$c['uid'], 'field' => $this->firstChangedField($c, $existing)];
                if ($verbose) {
                    $this->conflictResolver->logFieldDiff($c, $existing, 'tt_content');
                }
            } else {
                $iC++;
            }
        });

        $this->logger->info(sprintf('Pages:   %d new, %d changed, %d identical.', $nP, $uP, $iP));
        $this->logger->info(sprintf('Content: %d new, %d changed, %d identical.', $nC, $uC, $iC));
        if ($iP + $iC > 0) {
            $this->logger->info('Tip: use --delta to skip identical records.');
        }

        return [
            'pages' => ['new' => $nP, 'changed' => $uP, 'identical' => $iP],
            'tt_content' => ['new' => $nC, 'changed' => $uC, 'identical' => $iC],
            'samples' => $samples,
        ];
    }

    /**
     * Streamt eine Tabelle batch-weise (konstanter Speicher): pro Batch ein
     * Herkunfts-Mapping-Lookup, dann je Record die Callback mit dem gefundenen
     * Bestands-Record (oder null = neu).
     *
     * @param callable(array<string,mixed>, array<string,mixed>|null): void $onRecord
     */
    private function streamDiffBatches(ManifestReader $manifest, string $table, string $sourceId, callable $onRecord): void
    {
        $batchSize = $this->configurationService->getBatchSize();
        $batch = [];
        foreach ($manifest->eachRecord($table) as $record) {
            $batch[] = $record;
            if (count($batch) >= $batchSize) {
                $this->diffBatch($batch, $table, $sourceId, $onRecord);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->diffBatch($batch, $table, $sourceId, $onRecord);
        }
    }

    /**
     * @param array<int, array<string,mixed>> $batch
     * @param callable(array<string,mixed>, array<string,mixed>|null): void $onRecord
     */
    private function diffBatch(array $batch, string $table, string $sourceId, callable $onRecord): void
    {
        $existing = $this->existingRecordLocator->find($sourceId, $table, $batch);
        foreach ($batch as $record) {
            $onRecord($record, $existing[(int)$record['uid']] ?? null);
        }
    }

    /**
     * Erstes inhaltlich abweichende Feld zwischen Import- und Bestands-Record (für die
     * Vorschau, z. B. „bodytext changed"); System-/Volatile-Felder bleiben außen vor.
     *
     * @param array<string, mixed> $new
     * @param array<string, mixed> $existing
     */
    private function firstChangedField(array $new, array $existing): string
    {
        foreach ($new as $field => $value) {
            if (in_array($field, SystemFields::EXCLUDED, true) || in_array($field, self::SKIP_FIELDS, true) || is_array($value)) {
                continue;
            }
            if ((string)$value !== (string)($existing[$field] ?? '')) {
                return (string)$field;
            }
        }
        return 'content';
    }
}
