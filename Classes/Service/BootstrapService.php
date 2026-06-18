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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\ServerRequest;

class BootstrapService
{
    /**
     * Stellt den Backend-Kontext für CLI-Commands über den nativen TYPO3
     * CLI-Backend-User (_cli_) her. Ein bereits vorhandener BE_USER
     * (Functional-Tests, Backend-Modul) wird respektiert.
     */
    public function initializeBackendContext(int $workspaceId = 0): void
    {
        if (($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication) {
            $this->setWorkspace($workspaceId);
            return;
        }

        // Backend-Request-Kontext ist Voraussetzung für die BE-Authentifizierung.
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('http://localhost', 'GET'))
                ->withAttribute('applicationType', ApplicationType::BACKEND);
        }

        // Nativer CLI-Backend-User (_cli_) des TYPO3-Cores.
        Bootstrap::initializeBackendAuthentication();

        if (!(($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication)) {
            throw new \RuntimeException(
                'Backend-Authentifizierung fehlgeschlagen: Es konnte kein CLI-Backend-User (_cli_) initialisiert werden.'
            );
        }

        $this->setWorkspace($workspaceId);
    }

    private function setWorkspace(int $workspaceId): void
    {
        if (($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication) {
            $GLOBALS['BE_USER']->setWorkspace($workspaceId);
        }
    }
}
