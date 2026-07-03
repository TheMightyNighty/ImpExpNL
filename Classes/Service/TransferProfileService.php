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

use Robbi\ImpExpNL\Domain\ConflictStrategy;
use Robbi\ImpExpNL\Domain\TransferProfile;
use Robbi\ImpExpNL\Exception\ConfigException;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Lädt deklarative Transfer-Profile aus der YAML-Config (`transferProfiles`) und
 * löst den referenzierten Asset-Transport (`assetTransports`) auf. Setzt die
 * Kontext-/Umgebungs-Bindung durch (verhindert versehentliche Ausführung in der
 * falschen Umgebung).
 */
class TransferProfileService
{
    public function __construct(
        private readonly ConfigurationService $configurationService
    ) {}

    /**
     * @return string[]
     */
    public function list(): array
    {
        return array_keys($this->configurationService->getConfig()['transferProfiles'] ?? []);
    }

    public function has(string $name): bool
    {
        return isset($this->configurationService->getConfig()['transferProfiles'][$name]);
    }

    public function resolve(string $name): TransferProfile
    {
        $config = $this->configurationService->getConfig();
        $profiles = $config['transferProfiles'] ?? [];
        if (!isset($profiles[$name]) || !is_array($profiles[$name])) {
            throw new ConfigException(sprintf(
                "Transfer-Profil '%s' nicht gefunden. Verfügbar: %s",
                $name,
                $this->list() === [] ? '(keine)' : implode(', ', $this->list())
            ));
        }
        $profile = $profiles[$name];

        $transportName = (string)($profile['transport'] ?? 'external');
        $transports = $config['assetTransports'] ?? [];
        // Profil darf einen benannten Transport referenzieren ODER direkt einen Typ nennen.
        $transport = is_array($transports[$transportName] ?? null) ? $transports[$transportName] : ['type' => $transportName];
        $transport['type'] = (string)($transport['type'] ?? $transportName);

        try {
            $conflictStrategy = ConflictStrategy::fromInput($profile['conflictStrategy'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException(sprintf("Transfer-Profil '%s': %s", $name, $e->getMessage()));
        }

        // allowedTables: nicht gesetzt = alle registrierten Tabellen (null);
        // gesetzt (auch leer) = strikte Allowlist für den Registry-Import.
        $allowedTables = null;
        if (array_key_exists('allowedTables', $profile)) {
            $allowedTables = array_values(array_map('strval', (array)($profile['allowedTables'] ?? [])));
        }

        return new TransferProfile(
            $name,
            $transport,
            $conflictStrategy,
            array_values(array_map('strval', (array)($profile['allowedContext'] ?? []))),
            (bool)($profile['requireDryRun'] ?? false),
            $allowedTables,
        );
    }

    /**
     * Erzwingt die Umgebungs-Bindung: läuft das Profil nicht im erlaubten
     * TYPO3-Application-Context, wird abgebrochen (kein dev→prod auf dev).
     */
    public function assertContextAllowed(TransferProfile $profile): void
    {
        if ($profile->allowedContext === []) {
            return;
        }
        $current = (string)Environment::getContext();
        foreach ($profile->allowedContext as $allowed) {
            if ($current === $allowed || str_starts_with($current, $allowed . '/')) {
                return;
            }
        }
        throw new ConfigException(sprintf(
            "Transfer-Profil '%s' ist nur für Kontext [%s] vorgesehen; aktueller TYPO3-Kontext ist '%s'. Abbruch.",
            $profile->name,
            implode(', ', $profile->allowedContext),
            $current
        ));
    }
}
