<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Circo;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Free\FreePlacement;

/**
 * circo's circular layout, after Six and Tollis ("A Framework for Circular Drawings of
 * Networks", GD '99): the graph split into its biconnected blocks, each block's nodes on a
 * circle in the order a depth-first search meets them, as long round as its nodes are wide
 * and `mindist` apart; the largest block at the centre, and each other block hung outward
 * from its parent through the node they share. A single edge stands as a straight line out.
 */
final readonly class CircoEngine implements LayoutEngine
{
    public function __construct(
        /** The least room between neighbours on a circle, in the graph's unit: circo's `mindist`. */
        private float $apart = 72.0,
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
            if (null === $tail || null === $head || $tail === $head || \in_array($head, $neighbours[$tail], strict: true)) {
                continue;
            }
            $neighbours[$tail][] = $head;
            $neighbours[$head][] = $tail;
        }
        $size = 0.0;
        foreach ($graph->nodes as $node) {
            $size = \max($size, $node->width, $node->height);
        }
        $parts = [];
        $seen = [];
        foreach (\array_keys($neighbours) as $start) {
            if (\array_key_exists($start, $seen)) {
                continue;
            }
            $blocks = CircoBlocks::of($neighbours, $start);
            foreach ($blocks as $block) {
                foreach ($block as $node) {
                    $seen[$node] = true;
                }
            }
            $parts[] = $this->arrange($blocks, $size + $this->apart);
            yield null;
        }

        return FreePlacement::of($graph, FreePlacement::sideBySide($graph, $parts));
    }

    /**
     * Places a connected part's blocks: the largest first, on a circle about the origin, then
     * each block sharing a node with one placed, on a circle beyond that node, away from the
     * placed block's centre; blocks hung from one node fan out around that direction.
     *
     * @param list<list<int>> $blocks each block's nodes, in the order a depth-first search meets them
     * @return array<int, array{float, float}>
     */
    private function arrange(array $blocks, float $step): array
    {
        \usort($blocks, static fn (array $a, array $b): int => \count($b) <=> \count($a));
        $positions = [];
        $centres = [];
        $first = \array_shift($blocks) ?? [];
        $radius = self::radius(\count($first), $step);
        foreach ($first as $place => $node) {
            // Round from one step past the right, clockwise on paper, as circo sets a circle.
            $angle = (-($place + 1) * 2 * \M_PI) / \count($first);
            $positions[$node] = [$radius * \cos($angle), -$radius * \sin($angle)];
            $centres[$node] = [0.0, 0.0];
        }
        $hung = [];
        // Each pass hangs at least one block: the blocks of a connected part all join up.
        for ($passes = \count($blocks); [] !== $blocks && $passes >= 0; --$passes) {
            foreach ($blocks as $key => $block) {
                $joint = \array_values(\array_filter($block, static fn (int $node): bool => \array_key_exists($node, $positions)))[0] ?? null;
                if (null === $joint) {
                    continue;
                }
                unset($blocks[$key]);
                [$x, $y] = $positions[$joint];
                [$cx, $cy] = $centres[$joint];
                $length = \hypot($x - $cx, $y - $cy);
                $outward = $length > 0 ? \atan2($y - $cy, $x - $cx) : 0.0;
                // Each further block hung from one node turns a little further round it.
                $turn = $hung[$joint] ?? 0;
                $hung[$joint] = $turn + 1;
                $outward += (0 === ($turn % 2) ? 1 : -1) * \intdiv($turn + 1, num2: 2) * (\M_PI / 4);
                $others = \array_values(\array_filter($block, static fn (int $node): bool => $node !== $joint));
                $radius = 2 === \count($block) ? $step / 2 : self::radius(\count($block), $step);
                $centre = [$x + ($radius * \cos($outward)), $y + ($radius * \sin($outward))];
                foreach ($others as $place => $node) {
                    // From the joint, round the circle, evenly.
                    $angle = $outward + \M_PI + ((($place + 1) * 2 * \M_PI) / \count($block));
                    $positions[$node] = [$centre[0] + ($radius * \cos($angle)), $centre[1] + ($radius * \sin($angle))];
                    $centres[$node] = $centre;
                }
            }
        }

        return $positions;
    }

    /** A circle round enough for its nodes, each a step from the next along it. */
    private static function radius(int $count, float $step): float
    {
        return $count < 2 ? 0.0 : ($count * $step) / (2 * \M_PI);
    }
}
