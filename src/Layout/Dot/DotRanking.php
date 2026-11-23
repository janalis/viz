<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Render\Work;

/**
 * Ranks the nodes of a graph with clusters, as dot does by default. Each cluster is ranked on
 * its own, deepest first, then stands in its parent as one leader node offset by its nodes'
 * ranks; an edge into or out of a cluster becomes a soft constraint through a slack node,
 * which its weight pulls toward the edge's length without ever forbidding a cycle. Rank sets
 * sharing a node merge, and the nodes of a merged set into one; every set held at the top of its
 * scope shares one rank there, as every set held at the bottom does. Within a scope the hard
 * constraints are made acyclic by
 * {@see DotAcyclic}, then each connected component is ranked by network simplex from zero.
 */
final class DotRanking
{
    /** How much more a soft edge resists pointing backward than stretching. */
    private const int BACK = 10;

    /** @var list<array{int, int, int, int}> tail, head, minlen, weight */
    private readonly array $edges;
    /** @var array<int, list<int>> each cluster's children, the top level under -1 */
    private array $children = [];
    /** @var array<int, list<int>> the nodes directly inside each cluster, the top level under -1 */
    private array $members = [];
    /** @var array<int, int> each node in a rank set: the node standing for its merged sets */
    private array $group = [];
    /** @var array<int, RankKind> each merged group's kind: that of the last set merged in */
    private array $kind = [];
    /** @var array<int, true> the groups any min or source set reached */
    private array $top = [];
    /** @var array<int, true> the groups any max or sink set reached */
    private array $bottom = [];
    private readonly RankHolds $holds;

    /**
     * @param non-negative-int $nodes
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight; only edges that constrain ranks
     * @param list<int> $owner the innermost cluster of each node, -1 for none
     * @param list<int> $parents each cluster's parent cluster, -1 for none; a parent comes before its children
     * @param list<array{RankKind, list<int>}> $sets
     * @param RankSpread $spread how the top level spreads its nodes over ranks: none unless asked, as `of()` asks for a graph without clusters
     * @param array<int, int> $held each node a rank set took out of a cluster written before it, which it is ranked in all the same: the cluster the set is written in, -1 for the top level
     */
    public function __construct(
        private readonly int $nodes,
        array $edges,
        array $owner,
        array $parents,
        array $sets = [],
        private readonly RankSpread $spread = RankSpread::None,
        array $held = [],
    ) {
        $this->edges = $edges;
        foreach ($owner as $node => $cluster) {
            $this->members[$cluster][] = $node;
        }
        $this->children[-1] = [];
        foreach ($parents as $index => $parent) {
            $this->children[$parent][] = $index;
            $this->children[$index] ??= [];
        }
        foreach ($sets as [$kind, $members]) {
            $this->merge($kind, $members);
        }
        $roots = [];
        foreach (\array_keys($this->group) as $node) {
            $roots[$node] = $this->find($node);
        }
        $this->holds = new RankHolds($held, $roots, $edges);
    }

    /**
     * A graph's ranking as dot ranks it: cluster by cluster, spreading its nodes over ranks only
     * where it has no clusters; or, with `newrank`, all at once as if it had none, spread over
     * the whole graph.
     *
     * @param list<array{int, int, int, int}> $edges
     * @param list<int> $owner
     * @param list<int> $parents
     * @param list<array{RankKind, list<int>}> $sets
     * @param array<int, int> $held
     */
    public static function of(LayoutGraph $graph, array $edges, array $owner, array $parents, array $sets, array $held = []): self
    {
        $nodes = \count($graph->nodes);
        if ($graph->newRank) {
            return new self($nodes, $edges, \array_fill(0, $nodes, -1), [], $sets, RankSpread::Graph);
        }
        // A cluster written with no node leaves the spread on; one a rank set written after it emptied turns it off, as dot is seen to.
        $clustered = $graph->emptied || [] !== \array_filter($owner, static fn (int $cluster): bool => -1 !== $cluster);

        return new self($nodes, $edges, $owner, $parents, $sets, $clustered ? RankSpread::None : RankSpread::Components, $held);
    }

    /**
     * Merges a rank set into the groups it shares a node with, as dot does on its output: sets
     * that overlap land on one rank, the merged group takes the kind of the last set, and a
     * group any min or source set reached keeps to the top, one any max or sink set reached to
     * the bottom.
     *
     * @param list<int> $members
     */
    private function merge(RankKind $kind, array $members): void
    {
        if ([] === $members) {
            return;
        }
        $root = $this->find($members[0]);
        foreach ($members as $node) {
            $other = $this->find($node);
            if ($other === $root) {
                continue;
            }
            $this->group[$other] = $root;
            if (\array_key_exists($other, $this->top)) {
                $this->top[$root] = true;
            }
            if (\array_key_exists($other, $this->bottom)) {
                $this->bottom[$root] = true;
            }
        }
        $this->kind[$root] = $kind;
        if (RankKind::Min === $kind || RankKind::Source === $kind) {
            $this->top[$root] = true;
        }
        if (RankKind::Max === $kind || RankKind::Sink === $kind) {
            $this->bottom[$root] = true;
        }
    }

    /** The node standing for a node's merged rank sets, the node itself before any set. */
    private function find(int $node): int
    {
        $this->group[$node] ??= $node;
        while ($this->group[$node] !== $node) {
            $this->group[$node] = $this->group[$this->group[$node]];
            $node = $this->group[$node];
        }

        return $node;
    }

    /** @return \Generator<int, null, mixed, list<int>> each node's rank, from zero */
    public function ranks(): \Generator
    {
        $work = $this->scope(-1);
        yield from $work;
        $relative = $work->getReturn();
        $ranks = [];
        for ($node = 0; $node < $this->nodes; ++$node) {
            $ranks[] = $relative[$node] ?? 0;
        }

        return $ranks;
    }

    /**
     * Ranks one scope, the top level or a cluster, its clusters first.
     *
     * @return \Generator<int, null, mixed, array<int, int>> the rank of every node inside it, the least at zero
     */
    private function scope(int $cluster): \Generator
    {
        $inner = [];
        foreach ($this->children[$cluster] as $child) {
            $work = $this->scope($child);
            yield from $work;
            $inner[$child] = $work->getReturn();
        }
        [$units, $unitOf, $offset, $reach, $banded] = $this->units($cluster, $inner);
        [$hard, $soft] = $this->constraints($units, $unitOf, $offset, $reach);
        $hard = $this->bounds($units, DotAcyclic::orient(\count($units), $this->extremes($units, $hard)), $banded);
        $balanced = -1 === $cluster ? $this->spread : RankSpread::None;
        $ranks = \in_array(RankUnit::Both, $units, strict: true) ? \array_fill(0, \count($units), value: 0) : $this->solve(\count($units), $hard, $soft, $balanced);
        yield null;
        $result = [];
        foreach ($unitOf as $node => $unit) {
            $result[$node] = $ranks[$unit] + $offset[$node];
        }
        $least = [] === $result ? 0 : \min($result);
        foreach ($result as $node => $rank) {
            $result[$node] = $rank - $least;
        }

        return $result;
    }

    /**
     * Each node inside a scope stands for a unit: itself, its rank set, or its cluster's leader,
     * offset by its rank within the cluster.
     *
     * @param array<int, array<int, int>> $inner each child cluster's relative ranks
     * @return array{list<RankUnit>, array<int, int>, array<int, int>, array<int, int>, array<int, RankUnit>} each unit's kind, each node's unit, offset, and the offset its edges reach it at, and each cluster a min, max, source or sink set holds to an end of the scope, with that end's kind
     */
    private function units(int $cluster, array $inner): array
    {
        $members = $this->members[$cluster] ?? [];
        $roots = [];
        foreach ($members as $node) {
            // A held node is ranked in its cluster as a node of its own: its sets tie it in the scope they are written in.
            if (!\array_key_exists($node, $this->group) || $this->holds->holds($node)) {
                continue;
            }
            $roots[$node] = $this->find($node);
        }
        [$top, $bottom] = $this->bands($roots);
        $units = [];
        $unitOf = [];
        $offset = [];
        $setUnit = [];
        foreach ($members as $node) {
            [$key, $kind] = $this->unit($node, $roots[$node] ?? null, $top, $bottom);
            if (!\array_key_exists($key, $setUnit)) {
                $setUnit[$key] = \count($units);
                $units[] = $kind;
            }
            $unitOf[$node] = $setUnit[$key];
            $offset[$node] = 0;
        }
        foreach ($inner as $ranks) {
            // A cluster holding no node stands for nothing, as dot is seen to ignore it.
            if ([] === $ranks) {
                continue;
            }
            $leader = \count($units);
            $units[] = RankUnit::Cluster;
            foreach ($ranks as $node => $rank) {
                $unitOf[$node] = $leader;
                $offset[$node] = $rank;
            }
        }

        $ends = [];
        foreach ($this->top + $this->bottom as $root => $_) {
            $kind = $this->kind[$root];
            $ends[$root] = match (true) {
                \array_key_exists($root, $this->top) && \array_key_exists($root, $this->bottom) => RankUnit::Both,
                \array_key_exists($root, $this->top) => RankKind::Source === $kind ? RankUnit::Source : RankUnit::Min,
                default => RankKind::Sink === $kind ? RankUnit::Sink : RankUnit::Max,
            };
        }

        return $this->holds->tie($cluster, $units, $unitOf, $offset, $setUnit, $ends);
    }

    /**
     * What the merged groups held at the top and at the bottom of a scope become: one unit
     * for every group at the top, alone there when any of them ends a source set, and likewise
     * at the bottom; a group held at both ends ties the whole scope to one rank.
     *
     * @param array<int, int> $roots each node of the scope in a rank set, and its group
     * @return array{?RankUnit, ?RankUnit} the top unit's kind and the bottom unit's, null for none
     */
    private function bands(array $roots): array
    {
        $top = null;
        $bottom = null;
        foreach ($roots as $root) {
            $kind = $this->kind[$root];
            $high = \array_key_exists($root, $this->top);
            $low = \array_key_exists($root, $this->bottom);
            if ($high && $low) {
                return [RankUnit::Both, RankUnit::Both];
            }
            if ($high) {
                $top = RankUnit::Source === $top || RankKind::Source === $kind ? RankUnit::Source : RankUnit::Min;
            }
            if ($low) {
                $bottom = RankUnit::Sink === $bottom || RankKind::Sink === $kind ? RankUnit::Sink : RankUnit::Max;
            }
        }

        return [$top, $bottom];
    }

    /**
     * The unit a node of the scope stands for, by a key the scope's nodes share when they share
     * the unit, and its kind: every node held at one end shares that end's unit.
     *
     * @return array{string, RankUnit}
     */
    private function unit(int $node, ?int $root, ?RankUnit $top, ?RankUnit $bottom): array
    {
        return match (true) {
            null === $root => ['node '.$node, RankUnit::Node],
            RankUnit::Both === $top => ['both', RankUnit::Both],
            \array_key_exists($root, $this->top) => ['top', $top ?? RankUnit::Min],
            \array_key_exists($root, $this->bottom) => ['bottom', $bottom ?? RankUnit::Max],
            default => ['set '.$root, RankUnit::Same],
        };
    }

    /**
     * The scope's edges between units: hard between plain units, soft where a cluster is at
     * either end, as the span its nodes' offsets ask for. The soft edges come by their tails in
     * node order, then as written: where ranks of equal cost differ in which soft edge points
     * back, dot is measured to keep the earlier ones pointing forward.
     *
     * @param list<RankUnit> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset each node's offset in its unit
     * @param array<int, int> $reach the offset an edge from a node of no cluster reaches each node at
     * @return array{list<array{int, int, int, int}>, list<array{int, int, int, int}>}
     */
    private function constraints(array $units, array $unitOf, array $offset, array $reach): array
    {
        $hard = [];
        $soft = [];
        $inside = \array_keys($unitOf);
        \sort($inside);
        $last = \array_slice($inside, -2);
        foreach ($this->edges as [$tail, $head, $minlen, $weight]) {
            $from = $unitOf[$tail] ?? null;
            $to = $unitOf[$head] ?? null;
            if (null === $from || null === $to || $from === $to) {
                continue;
            }
            if ($this->holds->level($tail, $head, $last)) {
                continue;
            }
            if (RankUnit::Cluster === $units[$from] || RankUnit::Cluster === $units[$to]) {
                // Between two clusters, as dot is measured to rank them, an edge reaches both ends at their own offsets.
                $at = RankUnit::Cluster === $units[$from] && RankUnit::Cluster === $units[$to] ? $offset : $reach;
                $soft[$tail][] = [$from, $to, $minlen + $at[$tail] - $at[$head], $weight];
                continue;
            }
            $hard[] = [$from, $to, $minlen, $weight];
        }
        \ksort($soft);

        return [$hard, \array_merge(...$soft)];
    }

    /**
     * Points every edge into a min or source set, and out of a max or sink set, the way the set requires.
     *
     * @param list<RankUnit> $units
     * @param list<array{int, int, int, int}> $edges
     * @return list<array{int, int, int, int}>
     */
    private function extremes(array $units, array $edges): array
    {
        foreach ($edges as $index => [$tail, $head, $minlen, $weight]) {
            if (!$units[$head]->top() && !$units[$tail]->bottom()) {
                continue;
            }
            $edges[$index] = [$head, $tail, $minlen, $weight];
        }

        return $edges;
    }

    /**
     * Holds min and source sets at the top of the scope and max and sink sets at the bottom, a
     * source or sink alone on its rank.
     *
     * @param list<RankUnit> $units
     * @param list<array{int, int, int, int}> $edges
     * @param array<int, RankUnit> $banded clusters held at an end as if they were sets of that kind
     * @return list<array{int, int, int, int}>
     */
    private function bounds(array $units, array $edges, array $banded = []): array
    {
        $kinds = $banded + $units;
        foreach ($kinds as $set => $kind) {
            if (!$kind->top() && !$kind->bottom()) {
                continue;
            }
            foreach (\array_keys($units) as $other) {
                // Two units held at the same end bound nothing of each other.
                if ($other === $set || ($kind->top() === $kinds[$other]->top() && $kind->bottom() === $kinds[$other]->bottom())) {
                    continue;
                }
                $edges[] = $kind->top() ? [$set, $other, $kind->gap(), 0] : [$other, $set, $kind->gap(), 0];
            }
        }

        return $edges;
    }

    /**
     * Ranks each connected component of units by network simplex, a soft edge through a slack
     * node of its own; each component starts at zero on its units.
     *
     * @param list<array{int, int, int, int}> $hard
     * @param list<array{int, int, int, int}> $soft from, to, wanted span, weight
     * @param RankSpread $balance
     * @return list<int>
     */
    private function solve(int $units, array $hard, array $soft, RankSpread $balance): array
    {
        // The slack nodes come before the units, so the search for a tight tree starts from the
        // first soft edge: ties then fall the way dot is measured to break them.
        $slacks = \count($soft);
        $edges = \array_map(static fn (array $edge): array => [$edge[0] + $slacks, $edge[1] + $slacks, $edge[2], $edge[3]], $hard);
        $count = $slacks + $units;
        foreach ($soft as $slack => [$from, $to, $span, $weight]) {
            // Pulling the tail back costs more, so the edge keeps its direction when it can.
            $edges[] = [$slack, $from + $slacks, \max(0, -$span), self::BACK * $weight];
            $edges[] = [$slack, $to + $slacks, \max(0, $span), $weight];
        }
        $ranks = \array_fill(0, \max(0, $count), value: 0);
        foreach (self::components($count, $edges) as $nodes) {
            $local = \array_flip($nodes);
            $localEdges = [];
            foreach ($edges as [$tail, $head, $minlen, $weight]) {
                if (!\array_key_exists($tail, $local)) {
                    continue;
                }
                $localEdges[] = [$local[$tail], $local[$head], $minlen, $weight];
            }
            $solved = Work::run(new NetworkSimplex(\count($nodes), $localEdges)->solve(RankSpread::Components === $balance ? NetworkSimplex::BALANCE_RANKS : NetworkSimplex::BALANCE_NONE));
            $own = \array_map(static fn (int $node): int => $solved[$local[$node]], \array_filter($nodes, static fn (int $node): bool => $node >= $slacks));
            $least = [] === $own ? 0 : \min($own);
            foreach ($nodes as $position => $node) {
                $ranks[$node] = $solved[$position] - $least;
            }
        }
        if (RankSpread::Graph === $balance) {
            $ranks = RankBalance::spread($ranks, $edges);
        }

        return \array_slice($ranks, offset: $slacks, length: $units);
    }

    /**
     * Connected components, by union-find over every edge.
     *
     * @param list<array{int, int, int, int}> $edges
     * @return list<list<int>>
     */
    private static function components(int $count, array $edges): array
    {
        $root = \range(0, \max(0, $count - 1));
        $find = static function (int $node) use (&$root): int {
            while ($root[$node] !== $node) {
                $root[$node] = $root[$root[$node]];
                $node = $root[$node];
            }

            return $node;
        };
        foreach ($edges as [$tail, $head]) {
            $root[$find($tail)] = $find($head);
        }
        $components = [];
        for ($node = 0; $node < $count; ++$node) {
            $components[$find($node)][] = $node;
        }

        return \array_values($components);
    }
}
