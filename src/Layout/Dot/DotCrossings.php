<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The arithmetic of crossing minimisation over a ranked graph and a current order: crossings
 * between neighbouring ranks, each costing the product of its two edges' penalties; the cost
 * of two neighbours in one order or the other; and the weighted median of a node's
 * neighbours on the rank above or below, as Gansner et al. define it.
 */
final readonly class DotCrossings
{
    /** @var list<list<int>> layered edges above each node */
    public array $up;
    /** @var list<list<int>> layered edges below each node */
    public array $down;

    public function __construct(
        private DotLayers $layers,
    ) {
        $count = \count($layers->rank);
        $up = \array_fill(0, \max(0, $count), []);
        $down = \array_fill(0, \max(0, $count), []);
        foreach ($layers->edges as $index => [$tail, $head]) {
            $down[$tail][] = $index;
            $up[$head][] = $index;
        }
        $this->up = $up;
        $this->down = $down;
    }

    /** @return list<int> the layered edges on one side of a node */
    public function edges(int $node, Side $side): array
    {
        return Side::Above === $side ? $this->up[$node] : $this->down[$node];
    }

    /** The node at the far end of a layered edge, seen from one side. */
    public function far(int $edge, Side $side): int
    {
        return $this->layers->edges[$edge][Side::Above === $side ? 0 : 1];
    }

    /**
     * Every crossing between neighbouring ranks, with a Fenwick tree of penalties by head
     * position. As dot, an edge ends where its port puts it across its node, so two edges
     * from one node cross when their ports stand the other way round from their far ends.
     *
     * @param list<int> $position each node's index in its rank
     */
    public function total(array $position): int
    {
        $byRank = [];
        foreach ($this->layers->edges as [$tail, $head, , $penalty, $tailPort, $headPort]) {
            $byRank[$this->layers->rank[$tail]][] = [$position[$tail] + $tailPort, $position[$head] + $headPort, $penalty];
        }
        $total = 0;
        foreach ($byRank as $edges) {
            \usort($edges, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $heads = \array_values(\array_unique(\array_column($edges, 1), \SORT_REGULAR));
            \sort($heads);
            $slots = \array_flip(\array_map(\strval(...), $heads));
            $size = \count($heads);
            $tree = \array_fill(0, $size + 1, value: 0);
            $inserted = 0;
            foreach ($edges as [, $head, $penalty]) {
                $slot = $slots[(string) $head];
                $before = 0;
                for ($index = $slot + 1; $index > 0; $index -= $index & -$index) {
                    $before += $tree[$index];
                }
                $total += $penalty * ($inserted - $before);
                for ($index = $slot + 1; $index <= $size; $index += $index & -$index) {
                    $tree[$index] += $penalty;
                }
                $inserted += $penalty;
            }
        }

        return $total;
    }

    /**
     * What it costs to have $left just before $right, counting their edges up and down.
     *
     * @param list<int> $position
     * @param list<int> $left
     * @param list<int> $right
     */
    public function pair(array $position, array $left, array $right): int
    {
        $cost = 0;
        foreach (Side::cases() as $side) {
            $leftEnds = [];
            foreach ($left as $node) {
                foreach ($this->edges($node, $side) as $edge) {
                    $leftEnds[] = [$this->end($position, $edge, $side), $this->layers->edges[$edge][3]];
                }
            }
            foreach ($right as $node) {
                foreach ($this->edges($node, $side) as $edge) {
                    $end = $this->end($position, $edge, $side);
                    foreach ($leftEnds as [$leftEnd, $penalty]) {
                        $cost += $leftEnd > $end ? $penalty * $this->layers->edges[$edge][3] : 0;
                    }
                }
            }
        }

        return $cost;
    }

    /**
     * The weighted median of an item's neighbours' positions on one side; -1 when it has none there.
     *
     * @param list<int> $position
     * @param list<int> $item
     */
    public function median(array $position, array $item, Side $side): float
    {
        $positions = [];
        foreach ($item as $node) {
            foreach ($this->edges($node, $side) as $edge) {
                $positions[] = $this->end($position, $edge, $side);
            }
        }
        \sort($positions);
        $count = \count($positions);
        $middle = \intdiv($count, num2: 2);

        return match (true) {
            0 === $count => -1.0,
            1 === ($count % 2) => $positions[$middle],
            2 === $count => ($positions[0] + $positions[1]) / 2,
            default => self::weighted($positions, $middle),
        };
    }

    /**
     * Where a layered edge's far end stands, seen from one side: its node's position, and as
     * dot counts it, where across the node its port puts it.
     *
     * @param list<int> $position
     */
    private function end(array $position, int $edge, Side $side): float
    {
        return $position[$this->far($edge, $side)] + $this->layers->edges[$edge][Side::Above === $side ? 4 : 5];
    }

    /**
     * The median of an even number of positions, leaning to the side where they bunch.
     *
     * @param list<float> $positions
     */
    private static function weighted(array $positions, int $middle): float
    {
        $left = $positions[$middle - 1] - $positions[0];
        $right = $positions[\count($positions) - 1] - $positions[$middle];
        if (0.0 === ($left + $right)) {
            return ($positions[$middle - 1] + $positions[$middle]) / 2;
        }

        return (($positions[$middle - 1] * $right) + ($positions[$middle] * $left)) / ($left + $right);
    }
}
