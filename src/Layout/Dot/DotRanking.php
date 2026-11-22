<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

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
    /** @var list<int> the innermost cluster of each node, -1 at the top level */
    private readonly array $owner;
    /** @var array<int, list<int>> each cluster's children, the top level under -1 */
    private array $children = [];
    /** @var array<int, int> each node in a rank set: the node standing for its merged sets */
    private array $group = [];
    /** @var array<int, RankKind> each merged group's kind: that of the last set merged in */
    private array $kind = [];
    /** @var array<int, true> the groups any min or source set reached */
    private array $top = [];
    /** @var array<int, true> the groups any max or sink set reached */
    private array $bottom = [];

    /**
     * @param non-negative-int $nodes
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight; only edges that constrain ranks
     * @param list<int> $owner the innermost cluster of each node, -1 for none
     * @param list<int> $parents each cluster's parent cluster, -1 for none; a parent comes before its children
     * @param list<array{RankKind, list<int>}> $sets
     * @param NetworkSimplex::BALANCE_* $balance how a graph without clusters spreads its nodes
     */
    public function __construct(
        private readonly int $nodes,
        array $edges,
        array $owner,
        array $parents,
        array $sets = [],
        private readonly int $balance = NetworkSimplex::BALANCE_RANKS,
    ) {
        $this->edges = $edges;
        $this->owner = $owner;
        $this->children[-1] = [];
        foreach ($parents as $index => $parent) {
            $this->children[$parent][] = $index;
            $this->children[$index] ??= [];
        }
        foreach ($sets as [$kind, $members]) {
            $this->merge($kind, $members);
        }
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
        [$units, $unitOf, $offset] = $this->units($cluster, $inner);
        [$hard, $soft] = $this->constraints($units, $unitOf, $offset);
        $hard = $this->bounds($units, DotAcyclic::orient(\count($units), $this->extremes($units, $hard)));
        $balanced = [] === $this->children[$cluster] && -1 === $cluster ? $this->balance : NetworkSimplex::BALANCE_NONE;
        $ranks = \in_array('both', $units, strict: true) ? \array_fill(0, \count($units), value: 0) : $this->solve(\count($units), $hard, $soft, $balanced);
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
     * @return array{list<string>, array<int, int>, array<int, int>} each unit's kind, and each node's unit and offset
     */
    private function units(int $cluster, array $inner): array
    {
        $roots = [];
        for ($node = 0; $node < $this->nodes; ++$node) {
            if ($this->owner[$node] !== $cluster || !\array_key_exists($node, $this->group)) {
                continue;
            }
            $roots[$node] = $this->find($node);
        }
        $bands = $this->bands($roots);
        $units = [];
        $unitOf = [];
        $offset = [];
        $setUnit = [];
        for ($node = 0; $node < $this->nodes; ++$node) {
            if ($this->owner[$node] !== $cluster) {
                continue;
            }
            [$key, $kind] = $this->unit($node, $roots[$node] ?? null, $bands);
            if (!\array_key_exists($key, $setUnit)) {
                $setUnit[$key] = \count($units);
                $units[] = $kind;
            }
            $unitOf[$node] = $setUnit[$key];
            $offset[$node] = 0;
        }
        foreach ($inner as $ranks) {
            $leader = \count($units);
            $units[] = 'cluster';
            foreach ($ranks as $node => $rank) {
                $unitOf[$node] = $leader;
                $offset[$node] = $rank;
            }
        }

        return [$units, $unitOf, $offset];
    }

    /**
     * What the merged groups held at the top and at the bottom of a scope become: one unit
     * for every group at the top, alone there when any of them ends a source set, and likewise
     * at the bottom; a group held at both ends ties the whole scope to one rank.
     *
     * @param array<int, int> $roots each node of the scope in a rank set, and its group
     * @return array{?string, ?string} the kind of the top unit and of the bottom unit, null for none
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
                return ['both', 'both'];
            }
            if ($high) {
                $top = 'source' === $top || RankKind::Source === $kind ? 'source' : 'min';
            }
            if ($low) {
                $bottom = 'sink' === $bottom || RankKind::Sink === $kind ? 'sink' : 'max';
            }
        }

        return [$top, $bottom];
    }

    /**
     * The unit a node of the scope stands for, and its kind.
     *
     * @param array{?string, ?string} $bands
     * @return array{string, string}
     */
    private function unit(int $node, ?int $root, array $bands): array
    {
        if (null === $root) {
            return ['n'.$node, 'node'];
        }
        if ('both' === $bands[0]) {
            return ['both', 'both'];
        }
        if (\array_key_exists($root, $this->top)) {
            return ['top', (string) $bands[0]];
        }
        if (\array_key_exists($root, $this->bottom)) {
            return ['bottom', (string) $bands[1]];
        }

        return ['g'.$root, 'same'];
    }

    /**
     * The scope's edges between units: hard between plain units, soft where a cluster is at
     * either end, as the span its nodes' offsets ask for.
     *
     * @param list<string> $units
     * @param array<int, int> $unitOf
     * @param array<int, int> $offset
     * @return array{list<array{int, int, int, int}>, list<array{int, int, int, int}>}
     */
    private function constraints(array $units, array $unitOf, array $offset): array
    {
        $hard = [];
        $soft = [];
        foreach ($this->edges as [$tail, $head, $minlen, $weight]) {
            $from = $unitOf[$tail] ?? null;
            $to = $unitOf[$head] ?? null;
            if (null === $from || null === $to || $from === $to) {
                continue;
            }
            if ('cluster' === $units[$from] || 'cluster' === $units[$to]) {
                $soft[] = [$from, $to, $minlen + $offset[$tail] - $offset[$head], $weight];
                continue;
            }
            $hard[] = [$from, $to, $minlen, $weight];
        }

        return [$hard, $soft];
    }

    /**
     * Points every edge into a min or source set, and out of a max or sink set, the way the set requires.
     *
     * @param list<string> $units
     * @param list<array{int, int, int, int}> $edges
     * @return list<array{int, int, int, int}>
     */
    private function extremes(array $units, array $edges): array
    {
        foreach ($edges as $index => [$tail, $head, $minlen, $weight]) {
            $wrong = \in_array($units[$head], ['min', 'source'], strict: true) || \in_array($units[$tail], ['max', 'sink'], strict: true);
            if ($wrong) {
                $edges[$index] = [$head, $tail, $minlen, $weight];
            }
        }

        return $edges;
    }

    /**
     * Holds min and source sets at the top of the scope and max and sink sets at the bottom, a
     * source or sink alone on its rank.
     *
     * @param list<string> $units
     * @param list<array{int, int, int, int}> $edges
     * @return list<array{int, int, int, int}>
     */
    private function bounds(array $units, array $edges): array
    {
        foreach ($units as $set => $kind) {
            if (!\in_array($kind, ['min', 'source', 'max', 'sink'], strict: true)) {
                continue;
            }
            $gap = \in_array($kind, ['source', 'sink'], strict: true) ? 1 : 0;
            $top = \in_array($kind, ['min', 'source'], strict: true);
            foreach (\array_keys($units) as $other) {
                if ($other === $set) {
                    continue;
                }
                $edges[] = $top ? [$set, $other, $gap, 0] : [$other, $set, $gap, 0];
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
     * @param NetworkSimplex::BALANCE_* $balance
     * @return list<int>
     */
    private function solve(int $units, array $hard, array $soft, int $balance): array
    {
        $edges = $hard;
        $count = $units;
        foreach ($soft as [$from, $to, $span, $weight]) {
            // Pulling the tail back costs more, so the edge keeps its direction when it can.
            $slack = $count++;
            $edges[] = [$slack, $from, \max(0, -$span), self::BACK * $weight];
            $edges[] = [$slack, $to, \max(0, $span), $weight];
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
            $solved = Work::run(new NetworkSimplex(\count($nodes), $localEdges)->solve($balance));
            $own = \array_map(static fn (int $node): int => $solved[$local[$node]], \array_filter($nodes, static fn (int $node): bool => $node < $units));
            $least = [] === $own ? 0 : \min($own);
            foreach ($nodes as $position => $node) {
                $ranks[$node] = $solved[$position] - $least;
            }
        }

        return \array_slice($ranks, offset: 0, length: $units);
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
