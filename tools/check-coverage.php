<?php

declare(strict_types=1);

// Requires every statement in a PHPUnit Clover report to be covered, naming the lines that are not.

$report = $argv[1] ?? 'var/coverage/clover.xml';
$xml = @\simplexml_load_file($report);
if (false === $xml) {
    \fwrite(\STDERR, "Cannot read the coverage report {$report}\n");
    exit(1);
}
$missed = [];
foreach ($xml->xpath('//file') ?: [] as $file) {
    foreach ($file->line as $line) {
        if ('stmt' === (string) $line['type'] && '0' === (string) $line['count']) {
            $missed[(string) $file['name']][] = (int) $line['num'];
        }
    }
}
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ([] !== $missed || 0 === $statements) {
    foreach ($missed as $name => $lines) {
        \fwrite(\STDERR, \sprintf("%s: %s\n", $name, \implode(', ', $lines)));
    }
    \fwrite(\STDERR, "Line coverage must be 100%: {$covered}/{$statements} covered.\n");
    exit(1);
}
echo "Line coverage: 100% ({$covered}/{$statements}).\n";
