<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The nodes a `rank=same` set takes out of a cluster written before it, which dot is measured
 * to draw outside the cluster but rank in it all the same: each is ranked there as a node of its
 * own, and its sets tie it only in the scope they are written in, by rules found on dot's output,
 * not in the paper.
 */
final readonly class RankHolds
{
    /** @var array<int, int> each merged set's earliest written node, by its root */
    private array $earliest;

    /**
     * @param array<int, int> $held each held node: the cluster its set is written in, -1 for the top level
     * @param array<int, int> $roots each node in a rank set: the node standing for its merged sets
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight
     */
    public function __construct(
        private array $held,
        private array $roots,
        private array $edges,
    ) {
        $earliest = [];
        foreach ($roots as $node => $root) {
            $earliest[$root] = \min($earliest[$root] ?? $node, $node);
        }
        $this->earliest = $earliest;
    }

    /**
     * The clusters a graph's nodes are ranked in, and the held nodes by index.
     *
     * @param array<string, array{string, ?string}> $held each held node's cluster, and the cluster its set is written in, null at the top
     * @param array<string, int> $index each node's position
     * @param array<string, int> $clusters each cluster's position
     * @param list<int> $owner each node's innermost cluster as drawn, -1 for none
     * @return array{list<int>, array<int, int>}
     */
    public static function indexed(array $held, array $index, array $clusters, array $owner): array
    {
        $byIndex = [];
        foreach ($held as $id => [$cluster, $scope]) {
            $owner[$index[$id]] = $clusters[$cluster];
            $byIndex[$index[$id]] = null === $scope ? -1 : $clusters[$scope];
        }

        return [$owner, $byIndex];
    }

    public function holds(int $node): bool
    {
        return \array_key_exists($node, $this->held);
    }

    /**
     * Ties the rank sets written in a scope to the child clusters holding their nodes, as dot is
     * measured to: every unit and node of the scope, with the offset each node's edges reach it at.
     *
     * @param list<RankUnit> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @param array<string, int> $setUnit each set group's unit in the scope, by `set` and its root
     * @param array<int, true> $ends the groups held at the top or the bottom, which no cluster takes
     * @return array{list<RankUnit>, array<int, int>, array<int, int>, array<int, int>}
     */
    public function tie(int $scope, array $units, array $unitOf, array $offset, array $setUnit, array $ends): array
    {
        $reach = $offset;
        $heldIn = [];
        foreach ($this->held as $node => $written) {
            if ($written !== $scope || !\array_key_exists($node, $unitOf) || RankUnit::Cluster !== $units[$unitOf[$node]]) {
                continue;
            }
            $heldIn[$unitOf[$node]][] = $node;
        }
        /** @var array<int, array{int, int}> $joins */
        $joins = [];
        /** @var array<int, int> $groups */
        $groups = [];
        /** @var list<array{int, int, int}> $pending */
        $pending = [];
        foreach ($heldIn as $unit => $held) {
            $this->hold($unit, $held, $unitOf, $offset, $reach, $joins, $groups, $setUnit, $pending);
        }
        foreach ($pending as [$unit, $node, $at]) {
            [$target, $there] = $joins[$this->roots[$node]] ?? [$unit, $at];
            if ($target === $unit || $target < 0) {
                continue;
            }
            foreach (\array_keys($unitOf, $unit, strict: true) as $member) {
                $unitOf[$member] = $target;
                $offset[$member] += $there - $at;
                $reach[$member] += $there - $at;
            }
        }
        foreach ($groups as $placeholder => $first) {
            $at = \count($units);
            foreach ($units as $index => $kind) {
                $nodes = \array_keys($unitOf, $index, strict: true);
                if (RankUnit::Cluster === $kind || ([] !== $nodes && \min($nodes) > $first)) {
                    $at = $index;
                    break;
                }
            }
            \array_splice($units, $at, length: 0, replacement: [RankUnit::Same]);
            $shift = static fn (int $unit): int => match (true) {
                $unit === $placeholder => $at,
                $unit >= $at => $unit + 1,
                default => $unit,
            };
            $unitOf = \array_map($shift, $unitOf);
            $setUnit = \array_map($shift, $setUnit);
            $joins = \array_map(static fn (array $join): array => [$shift($join[0]), $join[1]], $joins);
        }
        foreach ($joins as $root => [$unit, $at]) {
            $set = $setUnit['set '.$root] ?? null;
            if (null === $set || \array_key_exists($root, $ends)) {
                continue;
            }
            foreach (\array_keys($unitOf, $set, strict: true) as $node) {
                if ($this->holds($node)) {
                    continue;
                }
                $unitOf[$node] = $unit;
                $offset[$node] = $at;
                $reach[$node] = $at;
            }
        }

        return [$units, $unitOf, $offset, $reach];
    }

    /**
     * Whether an edge between two held nodes of one merged set lies level, as dot is measured to
     * draw it: unless it points to the set's earliest written node, and then only where its ends
     * are the scope's last two nodes.
     *
     * @param list<int> $last the scope's last two nodes, in node order
     */
    public function level(int $tail, int $head, array $last): bool
    {
        if (!$this->holds($tail) || !$this->holds($head) || $this->roots[$tail] !== $this->roots[$head]) {
            return false;
        }
        if ($this->earliest($head) !== $head) {
            return true;
        }

        return \in_array($tail, $last, strict: true) && \in_array($head, $last, strict: true);
    }

    /**
     * Ties the sets of a cluster's held nodes, as dot is measured to rank them. The held nodes,
     * in node order, move together, apart from the rest, when the one before the cluster's last
     * node is held and its last is not, unless an edge joins the two; their sets then share a
     * unit of their own, each at its held node's offset, and edges reach the rest from the
     * rest's own top. Otherwise a set whose earliest written node is held joins the cluster at
     * the cluster's top, or at that node's own offset where it is the cluster's last; edges reach
     * that node at the same offset.
     *
     * @param list<int> $held the cluster's held nodes
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @param array<int, int> $reach
     * @param array<int, array{int, int}> $joins each set group to move, by its root: the unit it joins and its offset there
     * @param array<int, int> $groups each unit still to make for held nodes moved apart, by a number below zero: its first node
     * @param array<string, int> $setUnit
     * @param list<array{int, int, int}> $pending each cluster of held nodes alone whose set joins elsewhere: its unit, the node, and its offset
     */
    private function hold(int $unit, array $held, array &$unitOf, array $offset, array &$reach, array &$joins, array &$groups, array $setUnit, array &$pending): void
    {
        $inside = \array_keys($unitOf, $unit, strict: true);
        \sort($inside);
        $last = $inside[\count($inside) - 1];
        $rest = \array_values(\array_diff($inside, $held));
        if (\count($inside) >= 2 && \in_array($inside[\count($inside) - 2], $held, strict: true) && !\in_array($last, $held, strict: true)) {
            $joined = $this->joined($held, $rest);
            foreach ($held as $node) {
                $root = $this->roots[$node];
                $group = $unit;
                if (!$joined) {
                    $group = $joins[$root][0] ?? $setUnit['set '.$root] ?? null;
                    if (null === $group) {
                        $group = -1 - \count($groups);
                        $groups[$group] = $node;
                    }
                }
                $unitOf[$node] = $group;
                $reach[$node] = $joined ? $offset[$node] : 0;
                $joins[$root] = [$group, $offset[$node]];
            }
            $top = \min(\array_map(static fn (int $node): int => $offset[$node], $rest));
            foreach ($rest as $node) {
                $reach[$node] = $joined ? 0 : $offset[$node] - $top;
            }

            return;
        }
        $top = \min(\array_map(static fn (int $node): int => $offset[$node], $inside));
        foreach ($held as $node) {
            if ($this->earliest($node) !== $node) {
                if ([] === $rest) {
                    $pending[] = [$unit, $node, $node === $last ? $offset[$node] : $top];
                }
                continue;
            }
            $at = $node === $last ? $offset[$node] : $top;
            $reach[$node] = $at;
            $joins[$this->roots[$node]] ??= [$unit, $at];
        }
    }

    /**
     * Whether an edge joins a held node to one of the rest.
     *
     * @param list<int> $held
     * @param list<int> $rest
     */
    private function joined(array $held, array $rest): bool
    {
        $side = [];
        foreach ($held as $node) {
            $side[$node] = 1;
        }
        foreach ($rest as $node) {
            $side[$node] = 2;
        }
        foreach ($this->edges as [$tail, $head]) {
            if (2 === (($side[$tail] ?? 0) * ($side[$head] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    /** The earliest written node of a node's merged rank sets. */
    private function earliest(int $node): int
    {
        return $this->earliest[$this->roots[$node]];
    }
}
