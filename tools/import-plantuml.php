<?php

declare(strict_types=1);

// Imports PlantUML's default styles, its skinparam-to-style table and its diagrams' commands
// from its MIT distribution: the sources (plantuml-mit-<version>-sources.jar, unzipped) and
// the jar itself, with a JDK to run tools/PlantUmlCommands.java against it:
//   php tools/import-plantuml.php <unzipped sources> <version> <plantuml-mit jar>
// It copies skin/plantuml.skin and skin/rose.skin to resources/plantuml/, writes
// resources/plantuml/skinparams.json from FromSkinparamToStyle's table, and
// resources/plantuml/commands.json from the factories' command lists. It copies the themes
// whose own header licenses them under MIT to resources/plantuml/themes/, and lists every
// bundled theme's name in resources/plantuml/themes.json, `mit` naming those copied. Of the
// standard library, it exports with tools/PlantUmlStdlib.java the libraries whose info
// licenses them under MIT to resources/plantuml/stdlib/<library>.json, and indexes every
// library in resources/plantuml/stdlib.json: whether it is exported, or which it links to.

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
@\mkdir($target.'/themes', 0o755, true);
$themes = ['all' => [], 'mit' => []];
foreach (\glob($sources.'/themes/puml-theme-*.puml') ?: [] as $theme) {
    $name = \substr(\basename($theme, '.puml'), \strlen('puml-theme-'));
    $themes['all'][] = $name;
    if (1 !== \preg_match('/^---\n(?:.*\n)*?license:\s*MIT\s*\n(?:.*\n)*?---\n/', (string) \file_get_contents($theme))) {
        continue;
    }
    $themes['mit'][] = $name;
    \copy($theme, $target.'/themes/'.\basename($theme));
}
\sort($themes['all']);
\sort($themes['mit']);
\file_put_contents($target.'/themes.json', \json_encode(['version' => $version, ...$themes], \JSON_UNESCAPED_SLASHES)."\n");

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
    'net.sourceforge.plantuml.mindmap.MindMapDiagramFactory',
    'net.sourceforge.plantuml.wbs.WBSDiagramFactory',
    'net.sourceforge.plantuml.cheneer.ChenEerDiagramFactory',
    'net.sourceforge.plantuml.ebnf.PSystemEbnfFactory',
    'net.sourceforge.plantuml.regexdiagram.PSystemRegexFactory',
    'net.sourceforge.plantuml.board.BoardDiagramFactory',
    'net.sourceforge.plantuml.packetdiag.PacketDiagramFactory',
    'net.sourceforge.plantuml.nwdiag.NwDiagramFactory',
    'net.sourceforge.plantuml.wire.WireDiagramFactory',
    'net.sourceforge.plantuml.salt.PSystemSaltFactory',
];
$commands = \json_decode($run(\sprintf('java -cp %s PlantUmlCommands %s', \escapeshellarg($jar.\PATH_SEPARATOR.$classes), \implode(' ', $factories))), associative: true, flags: \JSON_THROW_ON_ERROR);
$families = [];
foreach ($commands as $factory => $list) {
    // `ClassDiagramFactory` is read as `class`, `PSystemEbnfFactory` as `ebnf`.
    $families[\lcfirst((string) \preg_replace('/^PSystem|DiagramFactory|Factory$/', '', \substr($factory, (int) \strrpos($factory, '.') + 1)))] = $list;
}
\file_put_contents($target.'/commands.json', \json_encode(['version' => $version, 'families' => $families], \JSON_UNESCAPED_SLASHES)."\n");
\fwrite(\STDOUT, \sprintf("Imported the commands of %d diagram families\n", \count($families)));

$run(\sprintf('javac -cp %s -d %s %s', \escapeshellarg($jar), \escapeshellarg($classes), \escapeshellarg(__DIR__.'/PlantUmlStdlib.java')));
$libraries = \array_map(static fn (string $path): string => \basename(\dirname($path)), \glob($sources.'/stdlib/*/info.spm') ?: []);
$stdlib = static fn (string $arguments): array => (array) \json_decode($run(\sprintf('java -cp %s PlantUmlStdlib %s', \escapeshellarg($jar.\PATH_SEPARATOR.$classes), $arguments)), associative: true, flags: \JSON_THROW_ON_ERROR);
$index = [];
foreach ($stdlib('--info '.\implode(' ', \array_map(\escapeshellarg(...), $libraries))) as $library => $exported) {
    $info = [];
    \preg_match_all('/^(\w+)=(.*)$/m', (string) $exported['info'], $info, \PREG_SET_ORDER);
    $fields = \array_column($info, 2, 1);
    $link = $fields['link'] ?? null;
    $index[$library] = ['mit' => null === $link && \str_starts_with((string) ($fields['license'] ?? ''), 'MIT'), 'link' => $link];
}
\ksort($index);
@\mkdir($target.'/stdlib', 0o755, true);
$mit = \array_keys(\array_filter($index, static fn (array $library): bool => $library['mit']));
foreach ($stdlib(\implode(' ', \array_map(\escapeshellarg(...), $mit))) as $library => $exported) {
    \file_put_contents($target.'/stdlib/'.$library.'.json', \json_encode($exported, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT)."\n");
}
\file_put_contents($target.'/stdlib.json', \json_encode(['version' => $version, 'libraries' => $index], \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n");
\fwrite(\STDOUT, \sprintf("Imported %d of the %d standard libraries\n", \count($mit), \count($index)));
