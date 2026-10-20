<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Circo;

/**
 * A connected part's biconnected blocks, by Tarjan's depth-first search: the most nodes no
 * single node's removal parts, each edge in exactly one block, nodes where blocks meet in
 * each of them. The search runs on its own stack, so a long path cannot exhaust PHP's.
 */
final class CircoBlocks
{
    /**
     * @param list<list<int>> $neighbours each node's neighbours, in the order their edges come
     * @return list<list<int>> each block's nodes in the order the search first met them; a lone node, a block of its own
     */
    public static function of(array $neighbours, int $start): array
    {
        $found = [$start => 0];
        $low = [$start => 0];
        /** @var list<array{int, int}> $edges the tree and back edges met, not yet in a block */
        $edges = [];
        $blocks = [];
        // Each frame: a node, its parent, and how many of its neighbours it has tried.
        $stack = [[$start, -1, 0]];
        while ([] !== $stack) {
            $top = \count($stack) - 1;
            [$node, $parent, $tried] = $stack[$top];
            if ($tried < \count($neighbours[$node])) {
                $stack[$top][2] = $tried + 1;
                $next = $neighbours[$node][$tried];
                if (!\array_key_exists($next, $found)) {
                    $found[$next] = \count($found);
                    $low[$next] = $found[$next];
                    $edges[] = [$node, $next];
                    $stack[] = [$next, $node, 0];
                    continue;
                }
                if ($next !== $parent && $found[$next] < $found[$node]) {
                    $edges[] = [$node, $next];
                    $low[$node] = \min($low[$node], $found[$next]);
                }
                continue;
            }
            \array_pop($stack);
            if (-1 === $parent) {
                continue;
            }
            $low[$parent] = \min($low[$parent], $low[$node]);
            if ($low[$node] < $found[$parent]) {
                continue;
            }
            // The parent cuts this node's side off: the edges since the one between them make a block.
            $order = [];
            do {
                [$a, $b] = \array_pop($edges) ?? [$parent, $node];
                $order[$found[$a]] = $a;
                $order[$found[$b]] = $b;
            } while ($a !== $parent || $b !== $node);
            \ksort($order);
            $blocks[] = \array_values($order);
        }

        return [] === $blocks ? [[$start]] : \array_reverse($blocks);
    }
}
