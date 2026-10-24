<?php

declare(strict_types=1);

// Imports the Open Iconic icons PlantUML draws for `<&name>` from the openiconic/ folder of
// its MIT distribution's jar — each an 8 by 8 SVG, its paths' `d` — into
// resources/plantuml/openiconic.json, for the native renderer to draw them:
//   php tools/import-openiconic.php <plantuml-mit jar>
// Open Iconic is by Waybury, under the MIT license (resources/licenses/openiconic.txt).

$jar = $argv[1] ?? '';
$zip = new ZipArchive();
if (!\is_file($jar) || true !== $zip->open($jar)) {
    \fwrite(\STDERR, "Usage: php tools/import-openiconic.php <plantuml-mit jar>\n");
    exit(1);
}
$icons = [];
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = (string) $zip->getNameIndex($index);
    $match = [];
    if (1 !== \preg_match('#^openiconic/([\w-]+)\.svg$#', $name, $match)) {
        continue;
    }
    $paths = [];
    \preg_match_all('/<path\b[^>]*\bd="([^"]*)"/', (string) $zip->getFromIndex($index), $paths);
    $icons[$match[1]] = $paths[1];
}
$zip->close();
\ksort($icons);
\file_put_contents(__DIR__.'/../resources/plantuml/openiconic.json', \json_encode($icons, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
\fwrite(\STDOUT, \sprintf("Imported %d icons\n", \count($icons)));
