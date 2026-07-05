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
            throw new ConfigException('Transport "push": "outbox" ist nicht konfiguriert (Zielverzeichnis für das Bundle).');
        }
        // Das Verzeichnis darf bewusst außerhalb des Projektverzeichnisses liegen
        // (gemountete Schleuse) – daher kein Projekt-Containment, aber es muss
        // existieren und beschreibbar sein. Es wird NICHT angelegt.
        if (!is_dir($outbox) || !is_writable($outbox)) {
            throw new ConfigException(sprintf(
                'Transport "push": Outbox "%s" existiert nicht oder ist nicht beschreibbar.',
                $outbox
            ));
        }

        // 1) Signiertes Bundle erzeugen (BundleTransport schreibt es neben die Export-Datei).
        $bundleResult = $this->bundleTransport->package($exportFile, $assets, $config);
        $bundlePath = (string)($bundleResult['artifact'] ?? '');
        if ($bundlePath === '' || !is_file($bundlePath)) {
            throw new ConfigException('Transport "push": Bundle-Erzeugung lieferte kein Artefakt.');
        }

        // 2) Atomar in die Outbox ablegen: temporär in .incoming/ schreiben, dann rename().
        $target = $outbox . '/' . basename($bundlePath);
        $stagingDir = $outbox . '/.incoming';
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0700, true) && !is_dir($stagingDir)) {
            throw new ConfigException(sprintf('Transport "push": Staging-Verzeichnis "%s" nicht anlegbar.', $stagingDir));
        }
        $staging = $stagingDir . '/' . basename($bundlePath) . '.part';

        // Verschieben statt Kopieren, wenn möglich (gleiches Dateisystem → rename).
        // Sonst copy in die Staging-Datei, dann rename in die Outbox (atomar auf dem Ziel-FS).
        if (!@rename($bundlePath, $staging)) {
            if (!@copy($bundlePath, $staging)) {
                throw new ConfigException(sprintf('Transport "push": Bundle konnte nicht nach "%s" übertragen werden.', $staging));
            }
            @unlink($bundlePath);
        }
        @chmod($staging, 0600);

        if (!@rename($staging, $target)) {
            @unlink($staging);
            throw new ConfigException(sprintf('Transport "push": finale Ablage nach "%s" fehlgeschlagen.', $target));
        }

        $message = sprintf('Bundle in Outbox abgelegt → %s. %s', $target, (string)$bundleResult['message']);
        $this->logger->info('Push-Transport: Bundle abgelegt', ['target' => $target]);

        return ['type' => 'push', 'artifact' => $target, 'message' => trim($message)];
    }
}
