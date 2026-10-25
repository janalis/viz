<?php

declare(strict_types=1);

// Writes small random directed graphs, every node a fixed 0.75 by 0.5 inch box with no text,
// for tools/compare-dot-layout.php to compare the native dot engine with an installed `dot`
// on layout alone: each graph a random tree joined by a few more edges, now and then one
// running back up or doubling another. The same seed writes the same graphs.
//   php tools/random-dot-graphs.php <directory> [count = 300] [seed = 7]

$directory = $argv[1] ?? '';
if ('' === $directory) {
    \fwrite(\STDERR, "Usage: php tools/random-dot-graphs.php <directory> [count] [seed]\n");
    exit(1);
}
$count = (int) ($argv[2] ?? 300);
\mt_srand((int) ($argv[3] ?? 7));
if (!\is_dir($directory)) {
    \mkdir($directory, recursive: true);
}
for ($graph = 0; $graph < $count; ++$graph) {
    $nodes = \mt_rand(4, 9);
    $wanted = \mt_rand($nodes - 1, (int) ($nodes * 1.6));
    $edges = [];
    for ($node = 1; $node < $nodes; ++$node) {
        $edges[] = [\mt_rand(0, $node - 1), $node];
    }
    while (\count($edges) < $wanted) {
        [$a, $b] = [\mt_rand(0, $nodes - 1), \mt_rand(0, $nodes - 1)];
        if ($a !== $b) {
            $edges[] = 0 !== \mt_rand(0, 5) ? [\min($a, $b), \max($a, $b)] : [$a, $b];
        }
    }
    \shuffle($edges);
    $source = "digraph { node [label=\"\", width=0.75, height=0.5, fixedsize=true];\n";
    foreach ($edges as [$a, $b]) {
        $source .= "n{$a} -> n{$b};\n";
    }
    \file_put_contents(\sprintf('%s/g%03d.dot', \rtrim($directory, '/'), $graph), $source."}\n");
}
