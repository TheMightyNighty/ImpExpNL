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

namespace Robbi\ImpExpNL\Transport;

use Psr\Log\LoggerInterface;
use Robbi\ImpExpNL\Exception\ConfigException;

/**
 * Ausgehender Transport ins Schutzgefälle: erzeugt ein signiertes Bundle (delegiert
 * an {@see BundleTransport}) und legt es **atomar** in ein konfiguriertes Outbox-
 * Verzeichnis ab. Der eigentliche Transfer über die Zonengrenze bleibt Sache des
 * Betriebs (rsync/SFTP/Datendiode) – ImpExpNL öffnet keinen Netzwerk-/Empfangskanal.
 *
 * Config:
 *   type: push
 *   outbox: /pfad/zur/schleuse        # Pflicht; existierend + beschreibbar; wird NICHT angelegt
 *   includeAssets / compressor / baseline  # an BundleTransport durchgereicht
 *
 * Atomare Ablage: das Bundle wird erst nach `<outbox>/.incoming/<name>.part`
 * geschrieben und dann per rename() auf `<outbox>/<name>.tar.gz` umbenannt. Ein
 * Konsument (Schleusenprozess) sieht so nie eine unvollständige Datei.
 */
class PushTransport implements TransportInterface
{
    public function __construct(
        private readonly BundleTransport $bundleTransport,
        private readonly LoggerInterface $logger
    ) {}

    public function getType(): string
    {
        return 'push';
    }

    public function package(string $exportFile, array $assets, array $config): array
    {
        $outbox = rtrim((string)($config['outbox'] ?? ''), '/');
        if ($outbox === '') {
            throw new ConfigException('Transport "push": "outbox" is not configured (target directory for the bundle).');
        }
        // The directory may deliberately lie outside the project directory
        // (mounted data lock) – so no project containment, but it must
        // exist and be writable. It is NOT created.
        if (!is_dir($outbox) || !is_writable($outbox)) {
            throw new ConfigException(sprintf(
                'Transport "push": outbox "%s" does not exist or is not writable.',
                $outbox
            ));
        }

        // 1) Generate the signed bundle (BundleTransport writes it next to the export file).
        $bundleResult = $this->bundleTransport->package($exportFile, $assets, $config);
        $bundlePath = (string)($bundleResult['artifact'] ?? '');
        if ($bundlePath === '' || !is_file($bundlePath)) {
            throw new ConfigException('Transport "push": bundle generation did not produce an artifact.');
        }

        // 2) Place it atomically into the outbox: write to .incoming/ first, then rename().
        $target = $outbox . '/' . basename($bundlePath);
        $stagingDir = $outbox . '/.incoming';
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0700, true) && !is_dir($stagingDir)) {
            throw new ConfigException(sprintf('Transport "push": staging directory "%s" could not be created.', $stagingDir));
        }
        $staging = $stagingDir . '/' . basename($bundlePath) . '.part';

        // Move instead of copy where possible (same filesystem → rename).
        // Otherwise copy into the staging file, then rename into the outbox (atomic on the target FS).
        if (!@rename($bundlePath, $staging)) {
            if (!@copy($bundlePath, $staging)) {
                throw new ConfigException(sprintf('Transport "push": bundle could not be transferred to "%s".', $staging));
            }
            @unlink($bundlePath);
        }
        @chmod($staging, 0600);

        if (!@rename($staging, $target)) {
            @unlink($staging);
            throw new ConfigException(sprintf('Transport "push": final placement to "%s" failed.', $target));
        }

        $message = sprintf('Bundle placed in outbox → %s. %s', $target, (string)$bundleResult['message']);
        $this->logger->info('Push transport: bundle placed', ['target' => $target]);

        return ['type' => 'push', 'artifact' => $target, 'message' => trim($message)];
    }
}
