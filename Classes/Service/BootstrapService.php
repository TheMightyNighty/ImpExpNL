<?php
declare(strict_types=1);

namespace Robbi\RobbiCopy\Service;

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\ServerRequest;

class BootstrapService
{
    /**
     * Initialisiert den Backend-Kontext für CLI-Commands.
     * In Testumgebungen mit vorhandenem BE_USER wird nur der Workspace gesetzt.
     *
     * v14: Bootstrap::initializeBackendAuthentication() erzeugt automatisch
     * einen _cli_ User. Manuelles Anlegen nur als Fallback für v12/v13.
     */
    public function initializeBackendContext(int $workspaceId = 0): void
    {
        // Bereits authentifiziert (Functional-Tests, Backend-Modul)?
        if (isset($GLOBALS['BE_USER']) && is_object($GLOBALS['BE_USER'])) {
            $this->setWorkspace($workspaceId);
            return;
        }

        // CLI-Kontext: Backend initialisieren
        try {
            Bootstrap::initializeBackendAuthentication();
        } catch (\Throwable $e) {
            // In Test-Umgebungen kann dies fehlschlagen — ignorieren
        }

        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            $request = new ServerRequest('http://localhost', 'GET');
            if (class_exists(ApplicationType::class)) {
                $request = $request->withAttribute('applicationType', ApplicationType::BACKEND);
            }
            $GLOBALS['TYPO3_REQUEST'] = $request;
        }

        // Fallback für v12/v13: Wenn Bootstrap keinen BE_USER erzeugt hat
        if (!isset($GLOBALS['BE_USER']) || !is_object($GLOBALS['BE_USER'])) {
            $GLOBALS['BE_USER'] = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
            $GLOBALS['BE_USER']->user['admin'] = 1;
            $GLOBALS['BE_USER']->user['uid'] = 1;
        }

        $this->setWorkspace($workspaceId);
    }

    private function setWorkspace(int $workspaceId): void
    {
        if (isset($GLOBALS['BE_USER']) && is_object($GLOBALS['BE_USER'])) {
            if (method_exists($GLOBALS['BE_USER'], 'setWorkspace')) {
                $GLOBALS['BE_USER']->setWorkspace($workspaceId);
            } else {
                $GLOBALS['BE_USER']->workspace = $workspaceId;
            }
        }
    }
}
