<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Nop;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Source\Unsupported;

/**
 * nop's layout, which lays nothing out: every node stands where its `pos` puts it, every edge
 * runs along its `pos` spline, its arrowheads' tips where they are written, its label at its
 * `lp`, all in points with y up, as a layout tool writes them; the whole moved to start at the
 * padding, as nop moves it. An edge without a spline runs straight.
 */
final readonly class NopEngine implements LayoutEngine
{
    /** How many straight steps each Bézier piece is drawn in. */
    private const int STEPS = 12;

    /**
     * @param array<string, array{float, float}> $positions each node's centre, by id
     * @param array<int, array{?array{float, float}, list<array{float, float}>, ?array{float, float}}> $curves each edge's tail tip, its Bézier control points, and its head tip, by index
     * @param array<int, array{float, float}> $labels each edge label's centre, by index
     */
    public function __construct(
        private array $positions,
        private array $curves = [],
        private array $labels = [],
    ) {}

    /**
     * @return \Generator<int, null, mixed, Placement>
     * @throws Unsupported when a node has no position
     */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        yield null;
        // Where everything starts: the nodes' boxes and the edges' control points, left and top.
        [$left, $top, $right, $bottom] = [\INF, \INF, -\INF, -\INF];
        foreach ($graph->nodes as $node) {
            $at = $this->positions[$node->id] ?? throw new Unsupported('The native DOT renderer does not lay out nodes without a position: '.$node->id);
            [$x, $y] = self::flip($at);
            [$left, $top] = [\min($left, $x - ($node->width / 2)), \min($top, $y - ($node->height / 2))];
            [$right, $bottom] = [\max($right, $x + ($node->width / 2)), \max($bottom, $y + ($node->height / 2))];
        }
        $splines = [];
        foreach ($this->curves as $index => [$start, $controls, $end]) {
            $points = self::flattened(\array_map(self::flip(...), $controls));
            $splines[$index] = [...(null === $start ? [] : [self::flip($start)]), ...$points, ...(null === $end ? [] : [self::flip($end)])];
            foreach ($splines[$index] as [$x, $y]) {
                [$left, $top, $right, $bottom] = [\min($left, $x), \min($top, $y), \max($right, $x), \max($bottom, $y)];
            }
        }
        $padding = $graph->spacing->padding;
        $shift = [$padding - $left, $padding - $top];
        $nodes = [];
        $endpoints = [];
        foreach ($graph->nodes as $id => $node) {
            [$x, $y] = self::moved($shift, self::flip($this->positions[$node->id]));
            $placed = new GraphNode($id, $node->id, $node->label, $x, $y, $node->width, $node->height, null, null, dashed: false);
            $nodes[$id] = $placed;
            $endpoints[$node->id] = $placed;
        }
        /** @var array<int, list<array{float, float}>> $moved */
        $moved = [];
        foreach ($splines as $index => $points) {
            foreach ($points as $at) {
                $moved[$index][] = self::moved($shift, $at);
            }
        }
        $routes = [];
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$endpoints[$edge->from] ?? null, $endpoints[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $routes[$edge->index] = [[$tail->x, $tail->y], [$head->x, $head->y]];
        }

        return new Placement(
            new GraphLayout($right - $left + (2 * $padding), $bottom - $top + (2 * $padding), $nodes, [], []),
            $endpoints,
            ['' => $graph->direction],
            $routes,
            \array_map(static fn (array $at): array => self::moved($shift, self::flip($at)), $this->labels),
            $moved,
        );
    }

    /**
     * @param array{float, float} $shift
     * @param array{float, float} $at
     * @return array{float, float}
     */
    private static function moved(array $shift, array $at): array
    {
        return [$at[0] + $shift[0], $at[1] + $shift[1]];
    }

    /**
     * A point with y up, with y down.
     *
     * @param array{float, float} $at
     * @return array{float, float}
     */
    private static function flip(array $at): array
    {
        return [$at[0], -$at[1]];
    }

    /**
     * Cubic Bézier pieces, each sharing its first control point with the last piece's end, drawn in straight steps.
     *
     * @param list<array{float, float}> $controls
     * @return list<array{float, float}>
     */
    private static function flattened(array $controls): array
    {
        $points = [] === $controls ? [] : [$controls[0]];
        for ($at = 0; ($at + 3) < \count($controls); $at += 3) {
            [$a, $b, $c, $d] = [$controls[$at], $controls[$at + 1], $controls[$at + 2], $controls[$at + 3]];
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $t = $step / self::STEPS;
                $u = 1 - $t;
                $points[] = [
                    (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
                    (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
                ];
            }
        }

        return $points;
    }
}
