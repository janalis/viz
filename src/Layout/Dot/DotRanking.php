<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Render\Work;

/**
 * Ranks the nodes of a graph with clusters, as dot does by default. Each cluster is ranked on
 * its own, deepest first, then stands in its parent as one leader node offset by its nodes'
 * ranks; an edge into or out of a cluster becomes a soft constraint through a slack node,
 * which its weight pulls toward the edge's length without ever forbidding a cycle. Nodes in a
 * same-rank set merge into one; a min or source set is held at the top of its scope and a max
 * or sink set at the bottom. Within a scope the hard constraints are made acyclic by
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
    /** @var array<int, array{RankKind, int}> each node's rank set kind and the set's index */
    private array $sets = [];

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
        foreach ($sets as $index => [$kind, $members]) {
            foreach ($members as $node) {
                $this->sets[$node] ??= [$kind, $index];
            }
        }
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
        $ranks = $this->solve(\count($units), $hard, $soft, $balanced);
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
        $units = [];
        $unitOf = [];
        $offset = [];
        $setUnit = [];
        for ($node = 0; $node < $this->nodes; ++$node) {
            if ($this->owner[$node] !== $cluster) {
                continue;
            }
            $set = $this->sets[$node] ?? null;
            $key = null === $set ? 'n'.$node : 's'.$set[1];
            if (!\array_key_exists($key, $setUnit)) {
                $setUnit[$key] = \count($units);
                $units[] = null === $set ? 'node' : $set[0]->value;
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
            foreach ($units as $other => $otherKind) {
                if ($other === $set || $otherKind === $kind) {
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
