<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** The order crossing minimisation meets a graph's edges in, which its initial order follows. */
final class DotMeeting
{
    /**
     * The edges in the order the ordering meets them, as dot meets them: node by node in the
     * order the nodes were made, each node's out-edges in turn, all the edges between two nodes
     * together, met with the first of them, and an edge pointing up the ranks met from its head,
     * as `dot` turns it round, after that node's own edges to the same node. `dot` is seen to set
     * a node's children in its own cluster first, then the rest, each left to right in the order
     * they were made, whatever order its edges are written in.
     *
     * @template T of array{int, int, int, float, float, float, float, int}
     * @param array<int, T> $edges by original index
     * @param list<int> $owner each node's cluster, -1 at the top
     * @param list<int> $ranks each node's rank
     * @return array<int, T>
     */
    public static function order(array $edges, array $owner, array $ranks): array
    {
        $outside = static fn (array $edge): int => (int) (-1 === $owner[$edge[0]] || $owner[$edge[0]] !== $owner[$edge[1]]);
        // Each pair of nodes is met at the turn of the first made of its edges' tails.
        $turn = [];
        foreach (\array_keys($edges) as $index) {
            [$tail, $head] = [$edges[$index][0], $edges[$index][1]];
            $pair = \min($tail, $head).'-'.\max($tail, $head);
            $turn[$pair] = \min($turn[$pair] ?? $tail, $tail);
        }
        $key = static function (int $index) use ($edges, $outside, $ranks, $turn): array {
            [$tail, $head] = [$edges[$index][0], $edges[$index][1]];
            $first = $turn[\min($tail, $head).'-'.\max($tail, $head)];

            return [$first, $outside($edges[$index]), $first === $tail ? $head : $tail, (int) ($ranks[$tail] > $ranks[$head]), $index];
        };
        \uksort($edges, static fn (int $a, int $b): int => $key($a) <=> $key($b));

        return $edges;
    }
}
