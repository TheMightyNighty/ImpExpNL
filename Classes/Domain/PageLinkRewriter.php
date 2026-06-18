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
 * Zentrale, zustandslose Logik für t3://page-Links.
 *
 * Früher war die Regex an drei Stellen dupliziert (LinkRewriterService,
 * TableRegistryService, ExportService). Diese Klasse ist die einzige Quelle
 * und damit gut unit-testbar.
 */
final class PageLinkRewriter
{
    private const PATTERN = '/t3:\/\/page\?uid=(\d+)/';

    /**
     * Schreibt alle t3://page?uid=<alt>-Links auf die neuen UIDs um.
     * Nicht gemappte UIDs bleiben unverändert.
     *
     * @param array<int,int> $pagesUidMap alte UID => neue UID
     */
    public static function rewrite(string $text, array $pagesUidMap): string
    {
        if ($text === '' || !str_contains($text, 't3://page')) {
            return $text;
        }
        return (string)preg_replace_callback(
            self::PATTERN,
            static function (array $m) use ($pagesUidMap): string {
                $old = (int)$m[1];
                return isset($pagesUidMap[$old]) ? 't3://page?uid=' . $pagesUidMap[$old] : $m[0];
            },
            $text
        );
    }

    /**
     * Extrahiert alle in einem Text referenzierten Seiten-UIDs.
     *
     * @return int[]
     */
    public static function extractPageUids(string $text): array
    {
        if ($text === '' || !str_contains($text, 't3://page')) {
            return [];
        }
        preg_match_all(self::PATTERN, $text, $matches);
        return array_map('intval', $matches[1]);
    }
}
