<?php

declare(strict_types=1);

// Imports PlantUML's default styles and its skinparam-to-style table from the sources of its
// MIT distribution (plantuml-mit-<version>-sources.jar, unzipped):
//   php tools/import-plantuml.php <unzipped sources> <version>
// It copies skin/plantuml.skin and skin/rose.skin to resources/plantuml/ and writes
// resources/plantuml/skinparams.json from FromSkinparamToStyle's table.

$sources = \rtrim($argv[1] ?? '', '/');
$version = $argv[2] ?? '';
$table = $sources.'/net/sourceforge/plantuml/style/FromSkinparamToStyle.java';
if ('' === $version || !\is_file($table) || !\is_file($sources.'/skin/plantuml.skin')) {
    \fwrite(\STDERR, "Usage: php tools/import-plantuml.php <unzipped plantuml-mit sources> <version>\n");
    exit(1);
}
$target = __DIR__.'/../resources/plantuml';
@\mkdir($target, 0o755, true);
foreach (['plantuml', 'rose'] as $skin) {
    \copy($sources.'/skin/'.$skin.'.skin', $target.'/'.$skin.'.skin');
}

$java = (string) \file_get_contents($table);
$static = \substr($java, (int) \strpos($java, 'static {'));
$static = \substr($static, 0, (int) \strpos($static, "\n\t}\n"));
$knowledge = [];
$convert = static function (string $key, string $property, array $names) use (&$knowledge): void {
    $knowledge[\strtolower($key)][] = [\array_map(static fn (string $name): string => \strtolower(\rtrim($name, '_')), $names), $property];
};
$font = static function (string $key, array $names) use ($convert): void {
    foreach (['FontSize', 'FontStyle', 'FontColor', 'FontName'] as $property) {
        $convert($key.$property, $property, $names);
    }
};
$names = static fn (string $list): array => \array_map(static fn (string $name): string => \trim(\str_replace('SName.', '', $name)), \explode(',', $list));
\preg_match_all('/^\s*(addConvert|addConFont|addMagic)\((.*)\);/m', $static, $calls, \PREG_SET_ORDER);
foreach ($calls as [, $call, $arguments]) {
    if ('addMagic' === $call) {
        $name = \trim(\str_replace('SName.', '', $arguments));
        $clean = \str_replace('_', '', $name);
        foreach (['BackgroundColor' => 'BackGroundColor', 'BorderColor' => 'LineColor', 'BorderThickness' => 'LineThickness', 'RoundCorner' => 'RoundCorner', 'DiagonalCorner' => 'DiagonalCorner', 'BorderStyle' => 'LineStyle'] as $suffix => $property) {
            $convert($clean.$suffix, $property, [$name]);
        }
        $font($clean, [$name]);
        $convert($clean.'Shadowing', 'Shadowing', [$name]);
        foreach (['FontSize', 'FontStyle', 'FontColor', 'FontName'] as $property) {
            $convert($clean.'Stereotype'.$property, $property, ['stereotype', $name]);
        }
        continue;
    }
    if (1 !== \preg_match('/^"([^"]+)",\s*(.*)$/', $arguments, $match)) {
        \fwrite(\STDERR, "Cannot read {$call}({$arguments})\n");
        exit(1);
    }
    if ('addConFont' === $call) {
        $font($match[1], $names($match[2]));
        continue;
    }
    // A property with no style names applies to the empty signature, which every element matches.
    [$property, $rest] = \explode(',', $match[2], 2) + [1 => ''];
    $convert($match[1], \trim(\str_replace('PName.', '', $property)), '' === \trim($rest) ? [] : $names($rest));
}
\file_put_contents($target.'/skinparams.json', \json_encode(['version' => $version, 'skinparams' => $knowledge], \JSON_UNESCAPED_SLASHES)."\n");
\fwrite(\STDOUT, \sprintf("Imported PlantUML %s: %d skinparams\n", $version, \count($knowledge)));
