<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** The order crossing minimisation meets a graph's edges in, which its initial order follows. */
final class DotMeeting
{
    /**
     * The edges in the order the ordering meets them, as dot meets them: node by node in the
     * order the nodes were made, each node's out-edges in turn, all the edges between two nodes
     * together, at the turn of the first made of their tails: an edge pointing up the ranks is
     * met at its own tail's turn where no edge runs back, and after its head's own edges to that
     * node where one does. `dot` is seen to set
     * a node's children in its own cluster or one inside it first, then the rest, each left to
     * right in the order they were made, whatever order its edges are written in.
     *
     * @template T of array{int, int, int, float, float, float, float, int}
     * @param array<int, T> $edges by original index
     * @param list<int> $owner each node's cluster, -1 at the top
     * @param list<int> $ranks each node's rank
     * @param list<int> $parents each cluster's parent, -1 for none
     * @return array<int, T>
     */
    public static function order(array $edges, array $owner, array $ranks, array $parents): array
    {
        // Whether an edge leaves its tail's cluster: its head stands neither in it nor in one inside it.
        $outside = static function (array $edge) use ($owner, $parents): int {
            for ($cluster = $owner[$edge[1]]; -1 !== $cluster && -1 !== $owner[$edge[0]]; $cluster = $parents[$cluster]) {
                if ($cluster === $owner[$edge[0]]) {
                    return 0;
                }
            }

            return 1;
        };
        // Each pair of nodes is met at the turn of the first made of its edges' tails.
        $turn = [];
        foreach (\array_keys($edges) as $index) {
            [$tail, $head] = [$edges[$index][0], $edges[$index][1]];
            $pair = \min($tail, $head).'-'.\max($tail, $head);
            $turn[$pair] = \min($turn[$pair] ?? $tail, $tail);
        }
        // Each edge's key worked out once, not at every comparison.
        $keys = [];
        foreach (\array_keys($edges) as $index) {
            [$tail, $head] = [$edges[$index][0], $edges[$index][1]];
            $first = $turn[\min($tail, $head).'-'.\max($tail, $head)];
            $keys[$index] = [$first, $outside($edges[$index]), $first === $tail ? $head : $tail, (int) ($ranks[$tail] > $ranks[$head]), $index];
        }
        \uksort($edges, static fn (int $a, int $b): int => $keys[$a] <=> $keys[$b]);

        return $edges;
    }
}
