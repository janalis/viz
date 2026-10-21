<?php

declare(strict_types=1);

// Records what PlantUML itself draws for each fixture of tests/fixtures/plantuml-parity, as
// the goldens PlantUmlParityTest grades the native drawing against: the diagram's size in
// points and the words it writes, each with its baseline. Run it with the jar tools/plantuml.lock pins and a JRE:
//   php tools/record-plantuml-parity.php <plantuml-mit jar> [--check] [<fixtures, such as activity/switch*>]
// It refuses a jar whose SHA-256 differs from the lock's. With --check it writes nothing and
// fails when PlantUML's drawing drifted from a golden: other words, or a height more than 3%
// off, which leaves room for the fonts of the machine it runs on.

$jar = $argv[1] ?? '';
$check = \in_array('--check', $argv, strict: true);
$only = \array_values(\array_diff(\array_slice($argv, offset: 2), ['--check']))[0] ?? '*/*';
$drift = 0;
$lock = \json_decode((string) \file_get_contents(__DIR__.'/plantuml.lock'), true, flags: \JSON_THROW_ON_ERROR);
if (!\is_file($jar)) {
    \fwrite(\STDERR, "Usage: php tools/record-plantuml-parity.php <plantuml-mit jar>\n");
    exit(1);
}
if (\hash_file('sha256', $jar) !== $lock['sha256']) {
    \fwrite(\STDERR, "The jar is not PlantUML {$lock['version']} as tools/plantuml.lock pins it.\n");
    exit(1);
}
$root = __DIR__.'/../tests/fixtures/plantuml-parity';
foreach (\glob($root.'/'.$only.'.puml') ?: [] as $fixture) {
    $process = \proc_open(['java', '-Djava.awt.headless=true', '-jar', $jar, '-tsvg', '-pipe'], [['file', $fixture, 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!\is_resource($process)) {
        exit(1);
    }
    $svg = (string) \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);
    $size = [];
    \preg_match('/width:(\d+(?:\.\d+)?)px;height:(\d+(?:\.\d+)?)px/', $svg, $size);
    $texts = [];
    \preg_match_all('/<text\b[^>]*\by="(-?\d+(?:\.\d+)?)"[^>]*>([^<]*)<\/text>/', $svg, $texts, \PREG_SET_ORDER);
    $placed = [];
    foreach ($texts as [, $y, $text]) {
        foreach (\preg_split('/\s+/u', \trim(\html_entity_decode($text, \ENT_QUOTES | \ENT_XML1, 'UTF-8')), flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $placed[] = [$word, (float) $y];
        }
    }
    \sort($placed);
    $words = \array_column($placed, 0);
    // Each word with its baseline, in points down the diagram, to grade where rows fall.
    $golden = ['plantuml' => $lock['version'], 'width' => (float) ($size[1] ?? 0), 'height' => (float) ($size[2] ?? 0), 'words' => $words, 'baselines' => $placed];
    $path = \substr($fixture, 0, -5).'.json';
    $name = \basename(\dirname($fixture)).'/'.\basename($fixture, '.puml');
    if ($check) {
        $recorded = \json_decode((string) \file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        if ($recorded['words'] !== $words || \abs($recorded['height'] - $golden['height']) > 0.03 * $recorded['height']) {
            echo $name, ': drifted, height ', $recorded['height'], ' now ', $golden['height'], "\n";
            ++$drift;
        }
        continue;
    }
    \file_put_contents($path, \json_encode($golden, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
    echo $name, "\n";
}
exit($drift > 0 ? 1 : 0);
