<?php

declare(strict_types=1);

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
