<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where {@see RankHolds} meets a min, max, source or sink set written after the cluster holding
 * its node: the cluster or the node kept at the set's end, as dot is measured to rank them.
 */
final readonly class RankEnds
{
    /**
     * @param array<int, int> $held each held node: the cluster its set is written in, -1 for the top level
     * @param array<int, int> $roots each node in a rank set: the node standing for its merged sets
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight
     */
    public function __construct(
        private array $held,
        private array $roots,
        private array $edges,
    ) {}

    /**
     * A cluster holding nodes of sets at both ends, as dot is measured to rank it: where the node
     * before its last is held at one end and its last at the other, that node moves apart to its
     * end and the last holds the rest at the other; where an edge from outside enters it at an
     * unheld last node, it keeps none of its sets' ends; otherwise it ties the whole scope to one
     * rank, as one set held at both ends does, its sets' ends met no further.
     *
     * @param array<int, int> $unitOf
     * @param array<int, RankUnit> $ends
     * @param array<int, list<int>> $heldIn
     * @param array<int, RankUnit> $atEnds
     * @param list<int> $both
     */
    public function bothEnds(int $unit, array $unitOf, array $ends, array &$heldIn, array &$atEnds, array &$both): void
    {
        $inside = \array_keys($unitOf, $unit, strict: true);
        \sort($inside);
        $count = \count($inside);
        $before = $inside[$count - 2] ?? -1;
        $last = $inside[$count - 1];
        if (\array_key_exists($before, $atEnds) && \array_key_exists($last, $atEnds) && $atEnds[$before]->top() !== $atEnds[$last]->top() && $count > \count($heldIn[$unit])) {
            $top = $atEnds[$before]->top();
            $heldIn[$unit] = \array_values(\array_filter($heldIn[$unit], static fn (int $node): bool => !\array_key_exists($node, $atEnds) || $atEnds[$node]->top() === $top));

            return;
        }
        $entered = false;
        foreach ($this->edges as [$tail, $head]) {
            $entered = $entered || ($head === $last && !\array_key_exists($last, $atEnds) && !\in_array($tail, $inside, strict: true));
        }
        if (!$entered) {
            $both[] = $last;
        }
        foreach ($heldIn[$unit] as $node) {
            unset($atEnds[$node]);
        }
        $heldIn[$unit] = \array_values(\array_filter($heldIn[$unit], fn (int $node): bool => !\array_key_exists($this->roots[$node], $ends)));
    }

    /**
     * Puts every unit held at one end of the scope on one rank, as every set held there shares
     * one: the units of sets and the clusters held there join the first cluster held there, or
     * the first unit, at its top, which then keeps to that end as a source or sink would where any
     * of them is one.
     *
     * @param RankUnit $shared the end's kind, min or max
     * @param RankUnit $alone the end's kind alone on its rank, source or sink
     * @param list<RankUnit> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @param array<int, int> $reach
     * @param array<int, RankUnit> $banded
     */
    public function share(RankUnit $shared, RankUnit $alone, array &$units, array &$unitOf, array &$offset, array &$reach, array &$banded): void
    {
        $held = [];
        foreach ($units as $unit => $kind) {
            $end = $banded[$unit] ?? $kind;
            if ($end === $shared || $end === $alone) {
                $held[$unit] = $end;
            }
        }
        if (\count($held) < 2) {
            return;
        }
        $clusters = \array_keys(\array_intersect_key($held, $banded));
        $target = $clusters[0] ?? \array_key_first($held);
        $kind = \in_array($alone, $held, strict: true) ? $alone : $shared;
        $topOf = static function (int $unit) use ($unitOf, $offset): int {
            $nodes = \array_keys($unitOf, $unit, strict: true);

            return [] === $nodes ? 0 : \min(\array_map(static fn (int $node): int => $offset[$node], $nodes));
        };
        $there = $topOf($target);
        foreach (\array_keys($held) as $unit) {
            if ($unit === $target) {
                continue;
            }
            $shift = $there - $topOf($unit);
            foreach (\array_keys($unitOf, $unit, strict: true) as $node) {
                $unitOf[$node] = $target;
                $offset[$node] += $shift;
                $reach[$node] += $shift;
            }
            unset($banded[$unit]);
            if (RankUnit::Cluster !== $units[$unit]) {
                $units[$unit] = RankUnit::Same;
            }
        }
        if (RankUnit::Cluster === $units[$target]) {
            $banded[$target] = $kind;

            return;
        }
        $units[$target] = $kind;
    }

    /**
     * Holds a node of a min, max, source or sink set written after its cluster at the set's end,
     * as dot is measured to: a node moved apart takes its unit there; one at its cluster's top
     * holds the whole cluster there, with the set's other nodes; one below leaves for the end alone.
     *
     * @param list<RankUnit> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @param array<int, int> $reach
     * @param array<string, int> $setUnit
     * @param array<int, RankUnit> $banded
     */
    public function toEnd(int $node, RankUnit $end, array &$units, array &$unitOf, array &$offset, array &$reach, array &$setUnit, array &$banded): void
    {
        $unit = $unitOf[$node];
        if (RankUnit::Cluster !== $units[$unit]) {
            if (RankUnit::Same === $units[$unit]) {
                $units[$unit] = $end;
            }

            return;
        }
        $key = $end->top() ? 'top' : 'bottom';
        $top = \min(\array_map(static fn (int $member): int => $offset[$member], \array_keys($unitOf, $unit, strict: true)));
        if ($offset[$node] === $top) {
            $banded[$unit] = $end;
            foreach (\array_keys($unitOf, $setUnit[$key] ?? -1, strict: true) as $member) {
                if (\array_key_exists($member, $this->held)) {
                    continue;
                }
                $unitOf[$member] = $unit;
                $offset[$member] = $offset[$node];
                $reach[$member] = $offset[$node];
            }

            return;
        }
        if (!\array_key_exists($key, $setUnit)) {
            $setUnit[$key] = \count($units);
            $units[] = $end;
        }
        $unitOf[$node] = $setUnit[$key];
        $offset[$node] = 0;
        $reach[$node] = 0;
    }
}
