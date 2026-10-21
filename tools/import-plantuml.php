<?php

declare(strict_types=1);

// Imports PlantUML's default styles, its skinparam-to-style table and its diagrams' commands
// from its MIT distribution: the sources (plantuml-mit-<version>-sources.jar, unzipped) and
// the jar itself, with a JDK to run tools/PlantUmlCommands.java against it:
//   php tools/import-plantuml.php <unzipped sources> <version> <plantuml-mit jar>
// It copies skin/plantuml.skin and skin/rose.skin to resources/plantuml/, writes
// resources/plantuml/skinparams.json from FromSkinparamToStyle's table, and
// resources/plantuml/commands.json from the factories' command lists.

$sources = \rtrim($argv[1] ?? '', '/');
$version = $argv[2] ?? '';
$jar = $argv[3] ?? '';
$table = $sources.'/net/sourceforge/plantuml/style/FromSkinparamToStyle.java';
if ('' === $version || !\is_file($table) || !\is_file($sources.'/skin/plantuml.skin') || !\is_file($jar)) {
    \fwrite(\STDERR, "Usage: php tools/import-plantuml.php <unzipped plantuml-mit sources> <version> <plantuml-mit jar>\n");
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

$classes = \sys_get_temp_dir().'/viz-plantuml-commands-'.\getmypid();
@\mkdir($classes, 0o755, true);
$run = static function (string $command): string {
    $output = [];
    \exec($command.' 2>&1', $output, $status);
    if (0 !== $status) {
        \fwrite(\STDERR, \implode("\n", $output)."\n");
        exit(1);
    }

    return \implode("\n", $output);
};
$run(\sprintf('javac -cp %s -d %s %s', \escapeshellarg($jar), \escapeshellarg($classes), \escapeshellarg(__DIR__.'/PlantUmlCommands.java')));
$factories = [
    'net.sourceforge.plantuml.sequencediagram.SequenceDiagramFactory',
    'net.sourceforge.plantuml.classdiagram.ClassDiagramFactory',
    'net.sourceforge.plantuml.activitydiagram.ActivityDiagramFactory',
    'net.sourceforge.plantuml.descdiagram.DescriptionDiagramFactory',
    'net.sourceforge.plantuml.statediagram.StateDiagramFactory',
    'net.sourceforge.plantuml.activitydiagram3.ActivityDiagramFactory3',
];
$commands = \json_decode($run(\sprintf('java -cp %s PlantUmlCommands %s', \escapeshellarg($jar.\PATH_SEPARATOR.$classes), \implode(' ', $factories))), associative: true, flags: \JSON_THROW_ON_ERROR);
$families = [];
foreach ($commands as $factory => $list) {
    $families[\lcfirst(\str_replace('DiagramFactory', '', \substr($factory, (int) \strrpos($factory, '.') + 1)))] = $list;
}
\file_put_contents($target.'/commands.json', \json_encode(['version' => $version, 'families' => $families], \JSON_UNESCAPED_SLASHES)."\n");
\fwrite(\STDOUT, \sprintf("Imported the commands of %d diagram families\n", \count($families)));
