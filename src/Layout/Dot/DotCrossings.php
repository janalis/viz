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
    /** @var array{array<int, list<array{int, float, int}>>, array<int, list<array{int, float, int}>>} each node's edges above, then below: the far node, where across it the edge ends, and its penalty */
    private array $ends;

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
        $above = [];
        $below = [];
        foreach ($layers->edges as $index => [$tail, $head, , $penalty, $tailPort, $headPort]) {
            $above[$head][] = [$tail, $tailPort, $penalty];
            $below[$tail][] = [$head, $headPort, $penalty];
        }
        $ends = [$above, $below];
        $this->ends = $ends;
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
     * What it costs to have $left just before $right, and $right just before $left, at once:
     * of two edges, one from each, on one side, the pair crosses in exactly one order, or in
     * neither where they end at one point.
     *
     * @param list<int> $position
     * @param list<int> $left
     * @param list<int> $right
     * @return array{int, int}
     */
    public function both(array $position, array $left, array $right): array
    {
        [$leftFirst, $rightFirst] = [0, 0];
        for ($side = 0; $side < 2; ++$side) {
            $leftEnds = [];
            foreach ($left as $node) {
                foreach ($this->ends[$side][$node] ?? [] as [$far, $port, $penalty]) {
                    $leftEnds[] = [$position[$far] + $port, $penalty];
                }
            }
            if ([] === $leftEnds) {
                continue;
            }
            foreach ($right as $node) {
                foreach ($this->ends[$side][$node] ?? [] as [$far, $port, $penalty]) {
                    $end = $position[$far] + $port;
                    foreach ($leftEnds as [$leftEnd, $leftPenalty]) {
                        $leftFirst += $leftEnd > $end ? $leftPenalty * $penalty : 0;
                        $rightFirst += $leftEnd < $end ? $leftPenalty * $penalty : 0;
                    }
                }
            }
        }

        return [$leftFirst, $rightFirst];
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
        $ends = $this->ends[Side::Above === $side ? 0 : 1];
        foreach ($item as $node) {
            // As dot, a neighbour counts where across it the edge ends, by its port.
            foreach ($ends[$node] ?? [] as [$far, $port]) {
                $positions[] = $position[$far] + $port;
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
