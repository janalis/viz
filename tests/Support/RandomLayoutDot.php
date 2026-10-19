<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

/**
 * Seeded random DOT graphs whose layout can be compared point for point: every node a fixed
 * size, no labels anywhere, with clusters, rank sets, edge lengths and weights, and every
 * rank direction. Nothing in them depends on how a font measures text.
 */
final class RandomLayoutDot
{
    /** @param int $clusters at most how many clusters */
    public static function source(int $seed, int $nodes = 8, int $clusters = 3): string
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $lines = ['node [shape=box, fixedsize=true, width=0.75, height=0.5, label=""]'];
        $direction = ['TB', 'TB', 'TB', 'LR', 'BT', 'RL'][$random->getInt(0, 5)];
        $lines[] = 'rankdir='.$direction;
        for ($node = 0; $node < $nodes; ++$node) {
            $lines[] = 'n'.$node.(0 === $random->getInt(0, 5) ? ' [width='.(0.5 + ($random->getInt(0, 4) / 4)).']' : '');
        }
        if ($clusters > 0) {
            $taken = [];
            $count = $random->getInt(0, $clusters);
            for ($cluster = 0; $cluster < $count; ++$cluster) {
                $members = [];
                $size = $random->getInt(1, 3);
                for ($index = 0; $index < $size; ++$index) {
                    $node = 'n'.$random->getInt(0, $nodes - 1);
                    if (!\array_key_exists($node, $taken)) {
                        $taken[$node] = true;
                        $members[] = $node;
                    }
                }
                if ([] === $members) {
                    continue;
                }
                $inner = 0 === $random->getInt(0, 3) && \count($members) > 1 ? ' subgraph cluster_'.$cluster.'_in { '.\array_pop($members).' }' : '';
                $lines[] = 'subgraph cluster_'.$cluster.' { '.\implode('; ', $members).';'.$inner.' }';
            }
        }
        if (0 === $random->getInt(0, 3)) {
            $lines[] = '{ rank=same; n'.$random->getInt(0, $nodes - 1).'; n'.$random->getInt(0, $nodes - 1).' }';
        }
        $edges = $random->getInt($nodes - 1, (int) ($nodes * 1.6));
        for ($edge = 0; $edge < $edges; ++$edge) {
            $tail = $random->getInt(0, $nodes - 1);
            $head = $random->getInt(0, $nodes - 1);
            if ($tail === $head) {
                continue;
            }
            $attributes = match ($random->getInt(0, 7)) {
                0 => ' [minlen=2]',
                1 => ' [weight=3]',
                2 => ' [constraint=false]',
                default => '',
            };
            $lines[] = 'n'.$tail.' -> n'.$head.$attributes;
        }

        return "digraph G {\n  ".\implode(";\n  ", $lines).";\n}\n";
    }
}
