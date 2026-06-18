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

namespace Robbi\ImpExpNL\Domain;

/**
 * Zentrale Liste der TYPO3-Systemfelder, die beim Import grundsätzlich nicht
 * aus den Quelldaten übernommen werden (UID/PID-Remapping, Versionierung,
 * Zeitstempel). Früher in mehreren Services dupliziert.
 */
final class SystemFields
{
    /**
     * @var string[]
     */
    public const EXCLUDED = [
        'uid', 'pid', 'tstamp', 'crdate',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_move_id',
        't3_origuid', 'l10n_diffsource',
    ];
}
