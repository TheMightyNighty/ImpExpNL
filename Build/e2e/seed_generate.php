<?php

declare(strict_types=1);

/*
 * Real-World-Test-Seed (Dev-Werkzeug, läuft IM Quell-Container).
 *
 * 1. Erzeugt N eindeutige Bilddateien in fileadmin (echte Bytes → realistisches
 *    Speicher-/Transfer-Verhalten, kein Inlining).
 * 2. Baut eine ImpExpNL-Importdatei (pages + tt_content[CType=image] +
 *    sys_file_reference + assets), die per `impexpnl:import` in die Quelle geht.
 *
 * Bewusst OHNE _meta.checksum: ohne IMPEXPNL_SIGNING_KEY würde die Verifikation
 * sonst greifen. Der Import prüft nur, wenn eine Prüfsumme vorhanden ist.
 *
 * Aufruf (im Container):
 *   php seed_generate.php --pages=2000 --assets=2000 --filesize=204800 \
 *       --fileadmin=/var/www/public/fileadmin --out=/var/www/seed.json
 */

$o = getopt('', ['pages:', 'assets:', 'filesize:', 'content-per-page:', 'fileadmin:', 'out:']);
$pages       = (int)($o['pages'] ?? 2000);
$assetCount  = (int)($o['assets'] ?? 2000);
$fileSize    = (int)($o['filesize'] ?? 200 * 1024); // ~200 KB → 2000×200KB ≈ 400 MB
$cpp         = (int)($o['content-per-page'] ?? 3);
$fileadmin   = rtrim((string)($o['fileadmin'] ?? '/var/www/public/fileadmin'), '/');
$out         = (string)($o['out'] ?? '/var/www/seed.json');
$assetDir    = $fileadmin . '/rwt';

@mkdir($assetDir, 0775, true);

// --- 1) echte Bilddateien erzeugen (eindeutiger Inhalt → eindeutige sha256) ---
// Minimaler valider JPEG-Header + Zufalls-Padding auf die Zielgröße.
$jpegHeader = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
$jpegFooter = "\xFF\xD9";
$padLen = max(0, $fileSize - strlen($jpegHeader) - strlen($jpegFooter));
$identifiers = [];
$t0 = microtime(true);
for ($i = 1; $i <= $assetCount; $i++) {
    $name = sprintf('img_%05d.jpg', $i);
    $path = $assetDir . '/' . $name;
    if (!is_file($path) || filesize($path) !== $fileSize) {
        $fh = fopen($path, 'w');
        fwrite($fh, $jpegHeader);
        // eindeutiges, nicht-komprimierbares-genug Padding (random_bytes blockweise)
        $written = 0;
        $seed = pack('N', $i);
        while ($written < $padLen) {
            $chunk = substr(str_repeat($seed . random_bytes(60), 1024), 0, min(65536, $padLen - $written));
            fwrite($fh, $chunk);
            $written += strlen($chunk);
        }
        fwrite($fh, $jpegFooter);
        fclose($fh);
    }
    $identifiers[] = '/rwt/' . $name;
    if ($i % 500 === 0) {
        fprintf(STDERR, "  %d/%d Dateien (%.1fs)\n", $i, $assetCount, microtime(true) - $t0);
    }
}
$totalBytes = $assetCount * $fileSize;
fprintf(STDERR, "Dateien fertig: %d (~%.0f MB) in %s\n", $assetCount, $totalBytes / 1048576, $assetDir);

// --- 2) Import-Datenblock bauen ---
$storageId = (int)($o['storage'] ?? 1);
$pagesArr = [];
$branching = 8;
for ($uid = 1; $uid <= $pages; $uid++) {
    $pid = $uid === 1 ? 0 : (int)(($uid - 2) / $branching) + 1;
    $pagesArr[] = [
        'uid' => $uid, 'pid' => $pid, 'title' => 'RWT Seite ' . $uid, 'doktype' => 1,
        'sys_language_uid' => 0, 'l10n_parent' => 0, 'sorting' => $uid * 256,
        'slug' => '/rwt-seite-' . $uid, 'hidden' => 0, 'deleted' => 0,
    ];
}

$ttContent = [];
$refs = [];
$cUid = 0;
$aIdx = 0;
foreach ($pagesArr as $page) {
    for ($n = 0; $n < $cpp; $n++) {
        $cUid++;
        $isImage = ($n === 0); // erstes Element je Seite trägt ein Bild
        $ttContent[] = [
            'uid' => $cUid, 'pid' => $page['uid'],
            'CType' => $isImage ? 'image' : 'text', 'colPos' => 0,
            'sys_language_uid' => 0, 'l18n_parent' => 0, 'sorting' => ($n + 1) * 256,
            'header' => 'Element ' . $cUid,
            'bodytext' => '<p>Inhalt ' . $cUid . '</p>',
            'hidden' => 0, 'deleted' => 0,
        ];
        if ($isImage && $aIdx < count($identifiers)) {
            $identifier = $identifiers[$aIdx++];
            $refs[] = [
                'uid' => 1000000 + $aIdx, 'pid' => $page['uid'],
                'uid_local' => $aIdx, // Quell-sys_file-UID (nur fürs uidMap-Mapping, real wird neu aufgelöst)
                'uid_foreign' => $cUid, 'tablenames' => 'tt_content',
                'fieldname' => 'image', 'sorting_foreign' => 1,
                'identifier' => $identifier, 'storage' => $storageId,
            ];
        }
    }
}

// assets-Manifest (size/sha256 berechnet der Export normalerweise; hier liefern wir
// realistische Werte mit, der Import braucht primär identifier+storage).
$assets = [];
foreach (array_slice($identifiers, 0, $aIdx) as $k => $identifier) {
    $assets[] = [
        'storage' => $storageId, 'identifier' => $identifier,
        'size' => $fileSize, 'sha256' => null,
    ];
}

$data = [
    'pages' => $pagesArr,
    'tt_content' => $ttContent,
    'sys_file_reference' => $refs,
    'assets' => $assets,
    '_meta' => [
        'export_version' => '5.0.0',
        'source_id' => 'rwt-source',
        'source_host' => 'rwt-seed',
        'record_counts' => [
            'pages' => count($pagesArr), 'tt_content' => count($ttContent),
            'sys_file_reference' => count($refs),
        ],
        // bewusst keine checksum
    ],
];

$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($out, $json);
fprintf(
    STDERR,
    "Seed: %d Seiten, %d Inhalte, %d Referenzen, %d Assets → %s (%.1f MB JSON)\n",
    count($pagesArr),
    count($ttContent),
    count($refs),
    count($assets),
    $out,
    strlen($json) / 1048576
);
