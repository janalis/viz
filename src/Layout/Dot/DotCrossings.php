<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The arithmetic of crossing minimisation over a ranked graph and a current order: crossings
 * between neighbouring ranks, each counting one whatever the edges' weights, as `dot`'s choices
 * between equally good orders are seen to count them, but once for each of the edges a shared
 * chain stands for, as `dot` counts them (the crossings it reports grow by the crossings of an
 * edge drawn again between the same nodes); the crossings of two neighbours in one
 * order or the other; and the weighted median of a node's neighbours on the rank above or
 * below, as Gansner et al. define it.
 *
 * A cluster not yet ordered from inside stands, on each of its ranks, as one slot: the edges
 * inside it count nothing, and from each of its ranks to the next one edge runs, which crosses
 * an edge at the cost of a thousand, as the crossings `dot` reports show.
 */
final readonly class DotCrossings
{
    /** What an edge crossing the run of a cluster not yet ordered from inside costs. */
    private const int THROUGH = 1000;

    /** @var list<list<int>> layered edges above each node */
    public array $up;
    /** @var list<list<int>> layered edges below each node */
    public array $down;
    /** @var array{array<int, list<array{int, float, int}>>, array<int, list<array{int, float, int}>>} each node's edges above, then below: the far node, where across it the edge ends, and how many edges it stands for */
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
        foreach ($layers->edges as $index => [$tail, $head, , , $tailPort, $headPort]) {
            $times = $layers->counts[$index];
            $above[$head][] = [$tail, $tailPort, $times];
            $below[$tail][] = [$head, $headPort, $times];
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
     * Every crossing between neighbouring ranks, with a Fenwick tree of edges by head
     * position. As dot, an edge ends where its port puts it across its node, so two edges
     * from one node cross when their ports stand the other way round from their far ends.
     *
     * @param list<int> $position each node's slot in its rank
     * @param array<int, int> $collapsed the nodes in a cluster not yet ordered from inside, and that cluster
     * @param array<int, array<int, int>> $blocks the slot of each such cluster on each of its ranks
     */
    public function total(array $position, array $collapsed = [], array $blocks = []): int
    {
        $byRank = [];
        foreach ($this->layers->edges as $index => [$tail, $head, , , $tailPort, $headPort]) {
            if (self::inside($collapsed, $tail, $head)) {
                continue;
            }
            $byRank[$this->layers->rank[$tail]][] = [$position[$tail] + $tailPort, $position[$head] + $headPort, $this->layers->counts[$index]];
        }
        foreach ($blocks as $slots) {
            foreach ($slots as $rank => $slot) {
                if (!\array_key_exists($rank + 1, $slots)) {
                    continue;
                }
                $byRank[$rank][] = [(float) $slot, (float) $slots[$rank + 1], self::THROUGH];
            }
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
            foreach ($edges as [, $head, $count]) {
                $slot = $slots[(string) $head];
                $before = 0;
                for ($index = $slot + 1; $index > 0; $index -= $index & -$index) {
                    $before += $tree[$index];
                }
                $total += ($inserted - $before) * $count;
                for ($index = $slot + 1; $index <= $size; $index += $index & -$index) {
                    $tree[$index] += $count;
                }
                $inserted += $count;
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
     * @param array<int, int> $collapsed
     * @param array<int, array<int, int>> $blocks
     * @return array{int, int}
     */
    public function both(array $position, array $left, array $right, array $collapsed = [], array $blocks = []): array
    {
        [$leftFirst, $rightFirst] = [0, 0];
        foreach ([Side::Above, Side::Below] as $side) {
            $leftEnds = $this->ends($position, $left, $side, $collapsed, $blocks);
            if ([] === $leftEnds) {
                continue;
            }
            foreach ($this->ends($position, $right, $side, $collapsed, $blocks) as [$end, $count]) {
                foreach ($leftEnds as [$leftEnd, $leftCount]) {
                    $leftFirst += $leftEnd > $end ? $count * $leftCount : 0;
                    $rightFirst += $leftEnd < $end ? $count * $leftCount : 0;
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
     * @param array<int, int> $collapsed
     * @param array<int, array<int, int>> $blocks
     */
    public function median(array $position, array $item, Side $side, array $collapsed = [], array $blocks = []): float
    {
        // As dot, a neighbour counts where across it the edge ends, by its port.
        $positions = \array_column($this->ends($position, $item, $side, $collapsed, $blocks), 0);
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
     * Where an item's edges on one side end, and how many edges each stands for: a cluster not
     * yet ordered from inside has no edges within it, but one to its own slot on the next rank.
     *
     * @param list<int> $position
     * @param list<int> $item
     * @param array<int, int> $collapsed
     * @param array<int, array<int, int>> $blocks
     * @return list<array{float, int}>
     */
    private function ends(array $position, array $item, Side $side, array $collapsed, array $blocks): array
    {
        $ends = [];
        foreach ($item as $node) {
            foreach ($this->ends[Side::Above === $side ? 0 : 1][$node] ?? [] as [$far, $port, $count]) {
                if (self::inside($collapsed, $node, $far)) {
                    continue;
                }
                $ends[] = [$position[$far] + $port, $count];
            }
        }
        $cluster = $collapsed[$item[0] ?? -1] ?? -1;
        $slot = $blocks[$cluster][$this->layers->rank[$item[0] ?? 0] + (Side::Above === $side ? -1 : 1)] ?? null;
        if (null !== $slot) {
            $ends[] = [(float) $slot, self::THROUGH];
        }

        return $ends;
    }

    /**
     * Whether two nodes stand in one cluster not yet ordered from inside.
     *
     * @param array<int, int> $collapsed
     */
    private static function inside(array $collapsed, int $a, int $b): bool
    {
        return \array_key_exists($a, $collapsed) && ($collapsed[$b] ?? -1) === $collapsed[$a];
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
