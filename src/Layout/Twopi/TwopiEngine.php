<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Twopi;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Free\FreePlacement;

/**
 * twopi's radial layout, as Wills describes it in "NicheWorks — Interactive Visualization of
 * Very Large Graphs" (1997): a root at the centre and every other node on the ring as many
 * gaps out as edges from it, along a breadth-first tree. Each node takes a wedge of its
 * parent's as wide as its share of the leaves below the parent, its children laid
 * counter-clockwise from its wedge's start, and stands at its wedge's middle. The root is the
 * one named, else the first node whose farthest node is nearest, as twopi chooses it.
 */
final readonly class TwopiEngine implements LayoutEngine
{
    public function __construct(
        /** How far apart the rings stand, in the graph's unit: twopi's `ranksep`. */
        private float $gap = 72.0,
        /** The node to stand at the centre, by id; null to choose one. */
        private ?string $root = null,
    ) {}

    /** @return \Generator<int, null, mixed, Placement> */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $index = [];
        foreach ($graph->nodes as $position => $node) {
            $index[$node->id] = $position;
        }
        $neighbours = \array_fill(0, \count($graph->nodes), []);
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$index[$edge->from] ?? null, $index[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $neighbours[$tail][] = $head;
            $neighbours[$head][] = $tail;
        }
        $parts = [];
        $seen = [];
        foreach (\array_keys($neighbours) as $start) {
            if (\array_key_exists($start, $seen)) {
                continue;
            }
            $part = \array_keys(self::reach($neighbours, $start));
            foreach ($part as $node) {
                $seen[$node] = true;
            }
            \sort($part);
            $named = null === $this->root ? null : $index[$this->root] ?? null;
            $root = null !== $named && \in_array($named, $part, strict: true) ? $named : self::centre($neighbours, $part);
            $parts[] = $this->radial($neighbours, $root);
            yield null;
        }

        return FreePlacement::of($graph, FreePlacement::sideBySide($graph, $parts));
    }

    /**
     * How many edges from a node every node it reaches stands, breadth first, and each one's
     * parent in that search.
     *
     * @param list<list<int>> $neighbours
     * @return array<int, array{int, int}> each node's depth and parent, -1 for the start
     */
    private static function reach(array $neighbours, int $start): array
    {
        $found = [$start => [0, -1]];
        $queue = [$start];
        for ($at = 0; $at < \count($queue); ++$at) {
            $node = $queue[$at];
            foreach ($neighbours[$node] as $next) {
                if (\array_key_exists($next, $found)) {
                    continue;
                }
                $found[$next] = [$found[$node][0] + 1, $node];
                $queue[] = $next;
            }
        }

        return $found;
    }

    /**
     * The first node whose farthest node is nearest.
     *
     * @param list<list<int>> $neighbours
     * @param list<int> $part
     */
    private static function centre(array $neighbours, array $part): int
    {
        $best = $part[0];
        $nearest = \PHP_INT_MAX;
        foreach ($part as $node) {
            $farthest = \max(\array_column(self::reach($neighbours, $node), 0));
            if ($farthest < $nearest) {
                [$best, $nearest] = [$node, $farthest];
            }
        }

        return $best;
    }

    /**
     * @param list<list<int>> $neighbours
     * @return array<int, array{float, float}> each node's centre, the root at the origin, y down
     */
    private function radial(array $neighbours, int $root): array
    {
        $tree = self::reach($neighbours, $root);
        $children = [];
        foreach ($tree as $node => [, $parent]) {
            if (-1 === $parent) {
                continue;
            }
            $children[$parent][] = $node;
        }
        // Leaves below each node, deepest first: a node with no children is a leaf of its own.
        /** @var array<int, int> $leaves */
        $leaves = [];
        foreach (\array_reverse(\array_keys($tree)) as $node) {
            $below = 0;
            foreach ($children[$node] ?? [] as $child) {
                $below += $leaves[$child];
            }
            $leaves[$node] = \max(1, $below);
        }
        $positions = [$root => [0.0, 0.0]];
        /** @var array<int, array{float, float}> $wedges each node's wedge: where it starts, and how wide */
        $wedges = [$root => [0.0, 2 * \M_PI]];
        foreach (\array_keys($tree) as $node) {
            [$start, $width] = $wedges[$node];
            foreach ($children[$node] ?? [] as $child) {
                $share = ($width * $leaves[$child]) / $leaves[$node];
                $wedges[$child] = [$start, $share];
                $angle = $start + ($share / 2);
                $radius = $tree[$child][0] * $this->gap;
                // Counter-clockwise from the right, as on paper, the screen's y running down.
                $positions[$child] = [$radius * \cos($angle), -$radius * \sin($angle)];
                $start += $share;
            }
        }

        return $positions;
    }
}
