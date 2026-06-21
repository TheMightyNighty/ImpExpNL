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

namespace Robbi\ImpExpNL\Exception;

use Robbi\ImpExpNL\Domain\ExitCode;

/**
 * Basis aller fachlichen ImpExpNL-Fehler. Trägt einen differenzierten Exit-Code,
 * den die Commands für CI/CD an die Shell zurückgeben.
 */
class ImpExpException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $exitCode = ExitCode::GENERIC,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
