<?php

declare(strict_types=1);

// Measures the advance of each printable character in PlantUML's default font, plain and
// bold, as the pinned jar writes it in SVG — each character drawn 20 times between two dots
// in a legend, its textLength less the dots' over 20, per point of the legend's font size —
// into resources/plantuml/font.json, for the native renderer to
// wrap text where PlantUML wraps it. Run it with the jar tools/plantuml.lock pins and a JRE:
//   php tools/measure-plantuml-font.php <plantuml-mit jar>
// The advances are those of the fonts of the machine it runs on, as PlantUML's are.

$jar = $argv[1] ?? '';
if (!\is_file($jar)) {
    \fwrite(\STDERR, "Usage: php tools/measure-plantuml-font.php <plantuml-mit jar>\n");
    exit(1);
}
$characters = [];
foreach ([...\range(0x20, 0x7E), ...\range(0xA0, 0xFF)] as $code) {
    $characters[] = \mb_chr($code);
}
$advances = [];
foreach (['plain' => '', 'bold' => '**'] as $face => $markup) {
    $lines = \array_map(static fn (string $char): string => $markup.'.'.\str_repeat($char, 20).'.'.$markup, ['', ...$characters]);
    $source = "@startuml\nlegend\n".\implode("\n", \array_map(static fn (string $line): string => \str_replace('\\', '\\\\', $line), $lines))."\nendlegend\n@enduml\n";
    $process = \proc_open(['java', '-Djava.awt.headless=true', '-jar', $jar, '-tsvg', '-pipe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!\is_resource($process)) {
        exit(1);
    }
    \fwrite($pipes[0], $source);
    \fclose($pipes[0]);
    $svg = (string) \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);
    $texts = [];
    \preg_match_all('/<text\b[^>]*font-size="([\d.]+)"[^>]*textLength="([\d.]+)"[^>]*>([^<]*)<\/text>/', $svg, $texts, \PREG_SET_ORDER);
    $dots = 0.0;
    foreach ($texts as [, , $length, $text]) {
        $dots = '..' === \html_entity_decode($text, \ENT_QUOTES | \ENT_XML1, 'UTF-8') ? (float) $length : $dots;
    }
    foreach ($texts as [, $size, $length, $text]) {
        $inner = \mb_substr(\html_entity_decode($text, \ENT_QUOTES | \ENT_XML1, 'UTF-8'), 1, -1);
        if (20 !== \mb_strlen($inner) || 1 !== \count(\array_unique(\mb_str_split($inner)))) {
            continue;
        }
        $advances[$face][\mb_substr($inner, 0, 1)] = \round(((float) $length - $dots) / 20 / (float) $size, 5);
    }
}
\file_put_contents(__DIR__.'/../resources/plantuml/font.json', \json_encode(['font' => 'SansSerif', 'size' => 1, 'advances' => $advances], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT)."\n");
\fwrite(\STDOUT, \sprintf("Measured %d plain and %d bold advances\n", \count($advances['plain'] ?? []), \count($advances['bold'] ?? [])));
