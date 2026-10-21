<?php

declare(strict_types=1);

// Records what PlantUML's own preprocessor gives out for each fixture of
// tests/fixtures/plantuml-preprocessor, as the goldens PlantUmlPreprocessorTest compares the
// native preprocessor with. Run it with the jar tools/plantuml.lock pins and a JRE:
//   php tools/record-plantuml-preprocessor.php <plantuml-mit jar> [--check]
// It refuses a jar whose SHA-256 differs from the lock's. With --check it writes nothing and
// fails when PlantUML's output drifted from a golden.

$jar = $argv[1] ?? '';
$check = \in_array('--check', $argv, strict: true);
$drift = 0;
$lock = \json_decode((string) \file_get_contents(__DIR__.'/plantuml.lock'), true, flags: \JSON_THROW_ON_ERROR);
if (!\is_file($jar)) {
    \fwrite(\STDERR, "Usage: php tools/record-plantuml-preprocessor.php <plantuml-mit jar> [--check]\n");
    exit(1);
}
if (\hash_file('sha256', $jar) !== $lock['sha256']) {
    \fwrite(\STDERR, "The jar is not PlantUML {$lock['version']} as tools/plantuml.lock pins it.\n");
    exit(1);
}
foreach (\glob(__DIR__.'/../tests/fixtures/plantuml-preprocessor/*.puml') ?: [] as $fixture) {
    $process = \proc_open(['java', '-Djava.awt.headless=true', '-jar', $jar, '-preproc', '-pipe'], [['file', $fixture, 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!\is_resource($process)) {
        exit(1);
    }
    $output = \rtrim(\str_replace("\r\n", "\n", (string) \stream_get_contents($pipes[1])), "\n")."\n";
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);
    $path = \substr($fixture, 0, -5).'.preproc';
    if ($check) {
        if ((string) \file_get_contents($path) !== $output) {
            echo \basename($fixture), ": drifted\n";
            ++$drift;
        }
        continue;
    }
    \file_put_contents($path, $output);
    echo \basename($fixture), "\n";
}
exit($drift > 0 ? 1 : 0);
