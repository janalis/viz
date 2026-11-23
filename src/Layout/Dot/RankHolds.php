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
        $joins = [];
        foreach ($heldIn as $unit => $held) {
            $this->hold($unit, $held, $units, $unitOf, $offset, $reach, $joins);
        }
        foreach ($joins as $root => [$unit, $at]) {
            $set = $setUnit['set '.$root] ?? null;
            if (null === $set || \array_key_exists($root, $ends)) {
                continue;
            }
            foreach (\array_keys($unitOf, $set, strict: true) as $node) {
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
     * @param array<int, int> $unitOf every node of the scope
     */
    public function level(int $tail, int $head, array $unitOf): bool
    {
        if (!$this->holds($tail) || !$this->holds($head) || $this->roots[$tail] !== $this->roots[$head]) {
            return false;
        }
        if ($this->earliest($head) !== $head) {
            return true;
        }
        $inside = \array_keys($unitOf);
        \sort($inside);
        $last = \array_slice($inside, -2);

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
     * @param list<RankUnit> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @param array<int, int> $reach
     * @param array<int, array{int, int}> $joins each set group to move, by its root: the unit it joins and its offset there
     */
    private function hold(int $unit, array $held, array &$units, array &$unitOf, array $offset, array &$reach, array &$joins): void
    {
        $inside = \array_keys($unitOf, $unit, strict: true);
        \sort($inside);
        $last = $inside[\count($inside) - 1];
        $rest = \array_values(\array_diff($inside, $held));
        if (\count($inside) >= 2 && \in_array($inside[\count($inside) - 2], $held, strict: true) && !\in_array($last, $held, strict: true)) {
            $group = $unit;
            if (!$this->joined($held, $rest)) {
                $group = \count($units);
                $units[] = RankUnit::Same;
            }
            foreach ($held as $node) {
                $unitOf[$node] = $group;
                $reach[$node] = 0;
                $joins[$this->roots[$node]] = [$group, $offset[$node]];
            }
            $top = \min(\array_map(static fn (int $node): int => $offset[$node], $rest));
            foreach ($rest as $node) {
                $reach[$node] = $offset[$node] - $top;
            }

            return;
        }
        $top = \min(\array_map(static fn (int $node): int => $offset[$node], $inside));
        foreach ($held as $node) {
            if ($this->earliest($node) !== $node) {
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
        foreach ($this->edges as [$tail, $head]) {
            if (\in_array($tail, $held, strict: true) ? \in_array($head, $rest, strict: true) : \in_array($tail, $rest, strict: true) && \in_array($head, $held, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /** The earliest written node of a node's merged rank sets. */
    private function earliest(int $node): int
    {
        return \min(\array_keys($this->roots, $this->roots[$node], strict: true));
    }
}
