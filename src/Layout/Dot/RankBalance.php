<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** The paper's balancing of ranks, after network simplex has ranked a graph. */
final class RankBalance
{
    /**
     * Moves each node with weighted edges and as much weight in as out to the feasible rank holding the
     * fewest nodes, node by node, as the paper balances ranks.
     *
     * @param list<int> $values each node's rank
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight
     * @return list<int>
     */
    public static function spread(array $values, array $edges): array
    {
        $in = \array_fill(0, \count($values), []);
        $out = \array_fill(0, \count($values), []);
        $weighted = [];
        foreach ($edges as $edge) {
            $out[$edge[0]][] = $edge;
            $in[$edge[1]][] = $edge;
            if ($edge[3] > 0) {
                $weighted[$edge[0]] = true;
                $weighted[$edge[1]] = true;
            }
        }
        $counts = [];
        foreach ($values as $value) {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        $highest = [] === $counts ? 0 : \max(\array_keys($counts));
        foreach (\array_keys($values) as $node) {
            $weightIn = 0;
            $weightOut = 0;
            $low = 0;
            $high = $highest;
            foreach ($in[$node] as [$tail, , $minlen, $weight]) {
                $weightIn += $weight;
                $low = \max($low, $values[$tail] + $minlen);
            }
            foreach ($out[$node] as [, $head, $minlen, $weight]) {
                $weightOut += $weight;
                $high = \min($high, $values[$head] - $minlen);
            }
            // A node with no edges keeps its rank, as `dot` is seen to keep it; edges of no weight, as a rank set's bounds, do not count.
            if ($weightIn !== $weightOut || !\array_key_exists($node, $weighted)) {
                continue;
            }
            $best = $values[$node];
            for ($rank = $low; $rank <= $high; ++$rank) {
                $best = ($counts[$rank] ?? 0) < ($counts[$best] ?? 0) ? $rank : $best;
            }
            if ($best === $values[$node]) {
                continue;
            }
            --$counts[$values[$node]];
            $counts[$best] = ($counts[$best] ?? 0) + 1;
            $values[$node] = $best;
        }

        return $values;
    }
}
