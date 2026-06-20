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
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
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
        // Aktiven Request immer mit applicationType=BACKEND versehen – PageRenderer/
        // DataHandler lesen $GLOBALS['TYPO3_REQUEST'] und werfen sonst
        // "No valid attribute applicationType found in request object".
        $this->ensureBackendRequest();

        $beUser = $GLOBALS['BE_USER'] ?? null;
        // Nur ein wirklich authentifizierter User (->user geladen) zählt; ein bloß
        // instanziierter, nicht eingeloggter BE_USER führt zu „… without permission“.
        if ($beUser instanceof BackendUserAuthentication && !empty($beUser->user['uid'])) {
            $this->setWorkspace($workspaceId);
            return;
        }

        // Nativen CLI-Backend-User (_cli_, Admin) erzeugen UND authentifizieren.
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class, $GLOBALS['TYPO3_REQUEST']);
        Bootstrap::initializeBackendAuthentication();
        // initializeBackendUser() kann den Request ersetzt haben -> erneut absichern.
        $this->ensureBackendRequest();

        if (empty($GLOBALS['BE_USER']->user['uid'])) {
            throw new \RuntimeException(
                'Backend-Authentifizierung fehlgeschlagen: Es konnte kein CLI-Backend-User (_cli_) authentifiziert werden.'
            );
        }

        $this->setWorkspace($workspaceId);
    }

    /**
     * Stellt sicher, dass $GLOBALS['TYPO3_REQUEST'] existiert und das Attribut
     * applicationType=BACKEND trägt. Idempotent: ein bereits gesetztes Attribut
     * (z. B. in Functional-Tests) bleibt unangetastet.
     */
    private function ensureBackendRequest(): void
    {
        // Wichtig: applicationType muss der INT-Wert REQUESTTYPE_BE sein – ApplicationType
        // ist ein Enum, fromRequest() verlangt aber is_int().
        $request = $GLOBALS['TYPO3_REQUEST'] ?? new ServerRequest('http://localhost', 'GET');
        if ($request->getAttribute('applicationType') === null) {
            $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;
    }

    private function setWorkspace(int $workspaceId): void
    {
        if (($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication) {
            $GLOBALS['BE_USER']->setWorkspace($workspaceId);
        }
    }
}
