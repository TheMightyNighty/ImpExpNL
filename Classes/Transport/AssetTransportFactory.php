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

use Robbi\ImpExpNL\Exception\ConfigException;

/**
 * Wählt den Asset-Transport anhand des Typ-Schlüssels. Default ist `external`
 * (kein zusätzliches Artefakt). Neue Transporte werden hier ergänzt – das übrige
 * Export-Flow bleibt unberührt.
 */
class AssetTransportFactory
{
    public function __construct(
        private readonly ExternalTransport $external,
        private readonly BundleTransport $bundle,
        private readonly PushTransport $push
    ) {}

    public function get(string $type): TransportInterface
    {
        return match ($type) {
            '', 'external' => $this->external,
            'bundle' => $this->bundle,
            'push' => $this->push,
            default => throw new ConfigException(sprintf(
                "Unbekannter Asset-Transport '%s'. Erlaubt: external, bundle, push.",
                $type
            )),
        };
    }
}
