<?php

declare(strict_types=1);

// Measures the advance of each printable character in PlantUML's default font, and in Java's
// serif and monospaced fonts its Creole sets text in, plain, bold, italic and both, as the
// pinned jar writes it in SVG — each character drawn 20 times between two dots in a legend,
// its textLength less the dots' over 20, per point of the legend's font size — into
// resources/plantuml/font.json, for the native renderer to lay text out and wrap it where
// PlantUML does. Run it with the jar tools/plantuml.lock pins and a JRE:
//   php tools/measure-plantuml-font.php <plantuml-mit jar>
// The advances are those of the fonts of the machine it runs on, as PlantUML's are.

$jar = $argv[1] ?? '';
if (!\is_file($jar)) {
    \fwrite(\STDERR, "Usage: php tools/measure-plantuml-font.php <plantuml-mit jar>\n");
    exit(1);
}
$characters = [];
// Latin, Greek and Cyrillic, punctuation, arrows, mathematical operators, control pictures —
// PlantUML draws a JSON null as one — geometric shapes and symbols, as its checkboxes.
$blocks = [[0x20, 0x7E], [0xA0, 0x17F], [0x370, 0x3FF], [0x400, 0x4FF], [0x2010, 0x205E], [0x2190, 0x21FF], [0x2200, 0x22FF], [0x2400, 0x2426], [0x25A0, 0x25FF], [0x2600, 0x26FF]];
foreach ($blocks as [$first, $last]) {
    foreach (\range($first, $last) as $code) {
        // As unassigned, a combining mark or a control — but a soft hyphen — has no advance of its own to measure.
        $char = \mb_chr($code);
        if (false !== $char && 1 === \preg_match('/^[\p{L}\p{N}\p{P}\p{S}\p{Zs}\x{AD}]$/u', $char)) {
            $characters[] = $char;
        }
    }
}
$measured = [];
foreach (['sans' => null, 'serif' => 'serif', 'monospaced' => 'monospaced'] as $family => $font) {
foreach (['plain' => ['', ''], 'bold' => ['<b>', '</b>'], 'italic' => ['<i>', '</i>'], 'bolditalic' => ['<b><i>', '</i></b>']] as $face => [$open, $close]) {
    [$open, $close] = null === $font ? [$open, $close] : ['<font:'.$font.'>'.$open, $close.'</font>'];
    // Creole's markup characters escaped, a tilde written as its entity, to be drawn as they are.
    $lines = \array_map(static fn (string $char): string => $open.'.'.\str_repeat(match ($char) {
        '~' => '&#126;',
        '"', '*', '-', '/', '_' => '~'.$char,
        default => $char,
    }, 20).'.'.$close, ['', ...$characters]);
    $source = "@startuml\nlegend\n".\implode("\n", $lines)."\nendlegend\n@enduml\n";
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
        $measured[$family][$face][\mb_substr($inner, 0, 1)] = \round(((float) $length - $dots) / 20 / (float) $size, 5);
    }
}
}
$advances = ['plain' => $measured['sans']['plain'] ?? [], 'bold' => $measured['sans']['bold'] ?? [], 'italic' => $measured['sans']['italic'] ?? [], 'bolditalic' => $measured['sans']['bolditalic'] ?? []];
\file_put_contents(__DIR__.'/../resources/plantuml/font.json', \json_encode(['font' => 'SansSerif', 'size' => 1, 'advances' => $advances, 'families' => ['serif' => $measured['serif'] ?? [], 'monospaced' => $measured['monospaced'] ?? []]], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT)."\n");
\fwrite(\STDOUT, \sprintf("Measured %d sans, %d serif and %d monospaced advances in each face\n", \count($advances['plain']), \count($measured['serif']['plain'] ?? []), \count($measured['monospaced']['plain'] ?? [])));
