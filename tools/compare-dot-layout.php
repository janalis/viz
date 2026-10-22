<?php

declare(strict_types=1);

// Compares where the native dot engine puts each node with where an installed `dot` puts it,
// over a directory of DOT files: `dot` only as a black box, by `dot -Tplain`. Both layouts are
// moved so their leftmost and topmost centres stand at 0; for each graph it prints the mean
// distance between a node's two centres, across plus down, in points, and how many pairs of
// nodes one rank holds in both stand in the other order. Graphviz's source is not used: see
// docs/graphviz-compatibility.md.
//   php tools/compare-dot-layout.php <directory of .dot files>

require __DIR__.'/../vendor/autoload.php';

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\DotPorts;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Render\Work;

$directory = $argv[1] ?? '';
$files = \glob(\rtrim($directory, '/').'/*.dot') ?: [];
if ([] === $files) {
    \fwrite(\STDERR, "Usage: php tools/compare-dot-layout.php <directory of .dot files>\n");
    exit(1);
}

/** @return array<string, array{float, float}> each node's centre, by name, as the native engine places it */
$native = static function (string $source): array {
    $graph = Work::run(new DotParser()->parse($source));
    $input = DotLayoutInput::from(
        $graph,
        static fn (DotNode $node): array => DotNodes::size($graph, $node),
        static fn (DotEdge $edge): array => DotNodes::edgeText($graph, $edge)->size(),
        static fn (DotSubgraph $cluster): array => DotNodes::clusterText($cluster)->size(),
        static fn (DotNode $node, string $port, float $width, float $height): ?array => DotPorts::anchor($graph, $node, DotNodes::record($graph, $node), $port, [-$width / 2, -$height / 2, $width / 2, $height / 2])[0],
    );
    $centres = [];
    foreach (Work::run(new DotEngine()->place($input))->graph->nodes as $node) {
        $centres[$node->name] = [$node->x, $node->y];
    }

    return $centres;
};

/** @return array<string, array{float, float}> each node's centre, by name, as `dot -Tplain` places it, y down */
$dot = static function (string $file): array {
    $centres = [];
    $height = 0.0;
    foreach (\explode("\n", (string) \shell_exec('dot -Tplain '.\escapeshellarg($file).' 2>/dev/null')) as $line) {
        $fields = \str_getcsv($line, ' ', '"', '\\');
        if ('graph' === ($fields[0] ?? '')) {
            $height = (float) ($fields[3] ?? 0) * 72;
        }
        if ('node' === ($fields[0] ?? '')) {
            $centres[(string) $fields[1]] = [(float) ($fields[2] ?? 0) * 72, $height - ((float) ($fields[3] ?? 0) * 72)];
        }
    }

    return $centres;
};

/**
 * @param array<string, array{float, float}> $centres
 * @return array<string, array{float, float}>
 */
$aligned = static function (array $centres): array {
    $left = \min(\array_column($centres, 0));
    $top = \min(\array_column($centres, 1));

    return \array_map(static fn (array $centre): array => [$centre[0] - $left, $centre[1] - $top], $centres);
};

[$distance, $count, $swapped, $pairs] = [0.0, 0, 0, 0];
foreach ($files as $file) {
    try {
        $ours = $native((string) \file_get_contents($file));
    } catch (\Throwable $failure) {
        echo \basename($file, '.dot'), ': the native engine failed: ', $failure->getMessage(), "\n";
        continue;
    }
    $theirs = $dot($file);
    $names = \array_values(\array_intersect(\array_keys($ours), \array_keys($theirs)));
    if (\count($names) < 2) {
        continue;
    }
    $ours = $aligned(\array_intersect_key($ours, \array_flip($names)));
    $theirs = $aligned(\array_intersect_key($theirs, \array_flip($names)));
    [$off, $wrong, $met] = [0.0, 0, 0];
    foreach ($names as $at => $name) {
        $off += \abs($ours[$name][0] - $theirs[$name][0]) + \abs($ours[$name][1] - $theirs[$name][1]);
        foreach (\array_slice($names, $at + 1) as $other) {
            // A pair on one rank in both, down or, laid out across, across: in the same order?
            foreach ([[1, 0], [0, 1]] as [$along, $within]) {
                if (\abs($ours[$name][$along] - $ours[$other][$along]) >= 1 || \abs($theirs[$name][$along] - $theirs[$other][$along]) >= 1) {
                    continue;
                }
                ++$met;
                $wrong += (int) (($ours[$name][$within] <=> $ours[$other][$within]) !== ($theirs[$name][$within] <=> $theirs[$other][$within]));
                break;
            }
        }
    }
    \printf("%-36s %3d nodes  %7.2f pt off  %d of %d pairs out of order\n", \basename($file, '.dot'), \count($names), $off / \count($names), $wrong, $met);
    [$distance, $count, $swapped, $pairs] = [$distance + $off, $count + \count($names), $swapped + $wrong, $pairs + $met];
}
\printf("All: %d nodes, %.2f pt off on average, %d of %d pairs out of order\n", $count, $distance / \max(1, $count), $swapped, $pairs);
