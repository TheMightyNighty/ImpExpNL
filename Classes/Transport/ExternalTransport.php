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

/**
 * Standard-Transport: erzeugt nichts zusätzlich. Export-Datei, Asset-Manifest
 * (`impexpnl_assets.json`) und rsync-Liste (`impexpnl_assets.txt`) liegen vor;
 * der eigentliche Datei-Transfer bleibt Operator-Sache (rsync/rclone/S3).
 */
class ExternalTransport implements TransportInterface
{
    public function getType(): string
    {
        return 'external';
    }

    public function package(string $exportFile, array $assets, array $config): array
    {
        return [
            'type' => 'external',
            'artifact' => null,
            'message' => sprintf(
                'Manifest + Asset-Liste geschrieben (%d Datei(en)). Transfer der Assets via rsync/rclone/S3 (Operator).',
                count($assets)
            ),
        ];
    }
}
