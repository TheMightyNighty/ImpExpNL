<?php

declare(strict_types=1);

/*
 * Erzeugt eine synthetische Robbi-Copy-Importdatei für Entwicklungs-/Lasttests.
 * Dev-Werkzeug, nicht Teil der Auslieferung.
 *
 * Beispiele:
 *   php Build/generate-testdata.php --pages=5000 --content-per-page=8 --out=var/big.json
 *   php Build/generate-testdata.php --pages=20000 --content-per-page=5 --format=jsonl --out=var/big.jsonl
 *
 * Anschließend auf einer Dev-Instanz importieren:
 *   vendor/bin/typo3 robbicopy:import var/big.json <ziel-pid>
 */

$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Composer-Autoload nicht gefunden. Bitte 'composer install' ausführen.\n");
    exit(1);
}
require $autoload;

$options = getopt('', ['pages:', 'content-per-page:', 'branching:', 'format:', 'out:']);
$pages = (int)($options['pages'] ?? 1000);
$contentPerPage = (int)($options['content-per-page'] ?? 5);
$branching = (int)($options['branching'] ?? 5);
$format = (string)($options['format'] ?? 'json');
$jsonl = $format === 'jsonl';
$out = (string)($options['out'] ?? ('testdata.' . ($jsonl ? 'jsonl' : 'json')));

$data = \Robbi\RobbiCopy\Tests\Functional\TestDataGenerator::build($pages, $contentPerPage, $branching);
\Robbi\RobbiCopy\Tests\Functional\TestDataGenerator::writeFile($data, $out, $jsonl);

printf(
    "Erzeugt: %d Seiten, %d Inhalte → %s (%s, %s)\n",
    count($data['pages']),
    count($data['tt_content']),
    $out,
    $jsonl ? 'JSONL' : 'JSON',
    number_format(filesize($out) / 1024, 1) . ' KB'
);
