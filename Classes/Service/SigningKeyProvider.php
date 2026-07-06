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

use Psr\Log\LoggerInterface;

/**
 * Einzige Bezugsquelle für das Signatur-Schlüsselmaterial. Kapselt den Zugriff
 * auf $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'] und die Umgebungsvariablen,
 * damit außerhalb dieser Klasse keine rohen Konfig-/Env-Zugriffe nötig sind.
 *
 * Bewusst NICHT Teil des {@see ConfigurationService}: der wrappt die YAML-Config
 * und braucht YamlFileLoader/PackageManager – Schlüsselmaterial kommt dagegen aus
 * ExtConf/Env und muss auch ohne DI-Container beziehbar bleiben (Unit-Tests,
 * Standalone-Werkzeuge wie Build/generate-testdata.php).
 *
 * Schlüssel-Quellen (in dieser Reihenfolge):
 *   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey']
 *   Umgebungsvariable IMPEXPNL_SIGNING_KEY
 * Analog signingKeyId/IMPEXPNL_SIGNING_KEY_ID sowie ['signingKeys'] (Alt-Schlüssel).
 */
class SigningKeyProvider
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Aktiver Signing-Key oder null (kein Manipulationsschutz konfiguriert).
     */
    public function getSigningKey(): ?string
    {
        $key = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            $env = getenv('IMPEXPNL_SIGNING_KEY');
            $key = is_string($env) ? $env : '';
        }
        $key = trim((string)$key);
        return $key !== '' ? $key : null;
    }

    /**
     * Optionale Kennung des aktiven Schlüssels (für die Rotation). Quelle:
     * ['signingKeyId'] bzw. IMPEXPNL_SIGNING_KEY_ID. Erlaubt sind nur
     * [A-Za-z0-9._-], da die ID Teil des Signatur-Strings wird (kein ':').
     */
    public function getSigningKeyId(): ?string
    {
        $id = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $env = getenv('IMPEXPNL_SIGNING_KEY_ID');
            $id = is_string($env) ? $env : '';
        }
        $id = trim((string)$id);
        if ($id === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1) {
            $this->logger?->warning("Signing key ID '$id' contains disallowed characters and is ignored (allowed: A-Z a-z 0-9 . _ -).");
            return null;
        }
        return $id;
    }

    /**
     * Alle für die Verifikation zulässigen Schlüssel: der aktive Schlüssel
     * (unter seiner Key-ID bzw. '' ohne ID) plus hinterlegte Alt-Schlüssel aus
     * ['signingKeys'] (Map key-id => Schlüssel). Der aktive Schlüssel gewinnt
     * bei ID-Kollision.
     *
     * @return array<string, string> key-id => Schlüssel
     */
    public function getVerificationKeys(): array
    {
        $keys = [];
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] ?? [];
        if (is_array($configured)) {
            foreach ($configured as $id => $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[(string)$id] = trim($key);
                }
            }
        }
        $active = $this->getSigningKey();
        if ($active !== null) {
            $keys[$this->getSigningKeyId() ?? ''] = $active;
        }
        return $keys;
    }
}
