<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Breaks the cycles of a set of constraints the way dot's ranking does: a depth-first search
 * from each node, in the order a search following edges out then in first meets them, takes
 * a node's edges out in the order their heads come, as measured on dot's output, and reverses
 * every edge it finds pointing back to a node still on its path.
 */
final class DotAcyclic
{
    /**
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight
     * @return list<array{int, int, int, int}>
     */
    public static function orient(int $nodes, array $edges): array
    {
        $out = self::outward($nodes, $edges);
        $state = \array_fill(0, \max(0, $nodes), value: 0);
        foreach (self::visitOrder($nodes, $edges) as $root) {
            if (0 !== $state[$root]) {
                continue;
            }
            $stack = [[$root, 0]];
            $state[$root] = 1;
            while ([] !== $stack) {
                $top = \count($stack) - 1;
                [$node, $cursor] = $stack[$top];
                if ($cursor >= \count($out[$node])) {
                    $state[$node] = 2;
                    \array_pop($stack);
                    continue;
                }
                $stack[$top][1] = $cursor + 1;
                $index = $out[$node][$cursor];
                $head = $edges[$index][1];
                if (1 === $state[$head]) {
                    [$tail, $edgeHead, $minlen, $weight] = $edges[$index];
                    $edges[$index] = [$edgeHead, $tail, $minlen, $weight];
                    continue;
                }
                if (0 === $state[$head]) {
                    $state[$head] = 1;
                    $stack[] = [$head, 0];
                }
            }
        }

        return $edges;
    }

    /**
     * The nodes in the order a depth-first search meets them, from each node in turn,
     * following edges out then in; a tail lists its edges out by the order of their heads, and
     * a head its edges in by the order of their tails.
     *
     * @param list<array{int, int, int, int}> $edges
     * @return list<int>
     */
    public static function visitOrder(int $nodes, array $edges): array
    {
        $out = [];
        $in = \array_fill(0, \max(0, $nodes), []);
        foreach (self::outward($nodes, $edges) as $tail => $indices) {
            $out[$tail] = \array_map(static fn (int $index): int => $edges[$index][1], $indices);
            foreach ($out[$tail] as $head) {
                $in[$head][] = $tail;
            }
        }
        $seen = [];
        $order = [];
        for ($root = 0; $root < $nodes; ++$root) {
            $stack = [$root];
            while ([] !== $stack) {
                $node = \array_pop($stack);
                if (\array_key_exists($node, $seen)) {
                    continue;
                }
                $seen[$node] = true;
                $order[] = $node;
                foreach (\array_reverse([...$out[$node], ...$in[$node]]) as $next) {
                    $stack[] = $next;
                }
            }
        }

        return $order;
    }

    /**
     * Each node's edges out, by index, in the order their heads come, as measured on dot's output.
     *
     * @param list<array{int, int, int, int}> $edges
     * @return list<list<int>>
     */
    private static function outward(int $nodes, array $edges): array
    {
        $out = \array_fill(0, \max(0, $nodes), []);
        foreach ($edges as $index => [$tail]) {
            $out[$tail][] = $index;
        }
        foreach ($out as $tail => $indices) {
            \usort($indices, static fn (int $a, int $b): int => $edges[$a][1] <=> $edges[$b][1]);
            $out[$tail] = $indices;
        }

        return $out;
    }
}
