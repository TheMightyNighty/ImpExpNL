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
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\ServerRequest;

class BootstrapService
{
    /**
     * Stellt den Backend-Kontext für CLI-Commands über den nativen TYPO3
     * CLI-Backend-User (_cli_) her. Ein bereits *authentifizierter* BE_USER
     * (Functional-Tests, Backend-Modul) wird respektiert.
     */
    public function initializeBackendContext(int $workspaceId = 0): void
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        // Wichtig: nur ein wirklich authentifizierter User (->user geladen) zählt.
        // Ein bloß instanziierter, aber nicht eingeloggter BE_USER (->user leer)
        // führt sonst zu „Attempt to modify table … without permission“.
        if ($beUser instanceof BackendUserAuthentication && !empty($beUser->user['uid'])) {
            $this->setWorkspace($workspaceId);
            return;
        }

        // Backend-Request-Kontext ist Voraussetzung für die BE-Authentifizierung.
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('http://localhost', 'GET'))
                ->withAttribute('applicationType', ApplicationType::BACKEND);
        }

        // Nativen CLI-Backend-User (_cli_, Admin) erzeugen UND authentifizieren.
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class, $GLOBALS['TYPO3_REQUEST']);
        Bootstrap::initializeBackendAuthentication();

        if (empty($GLOBALS['BE_USER']->user['uid'])) {
            throw new \RuntimeException(
                'Backend-Authentifizierung fehlgeschlagen: Es konnte kein CLI-Backend-User (_cli_) authentifiziert werden.'
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
