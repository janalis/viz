<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Patchwork;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;

/**
 * patchwork's layout: a squarified treemap, as Bruls, Huizing and van Wijk describe it
 * ("Squarified Treemaps", 2000). The drawing is a square as large as all its nodes' areas
 * together; each cluster takes a rectangle as large as what it holds, and each node one as
 * large as its `area`. Largest first, items fill rows along the shorter side of what is left,
 * a row taking another item while that brings its worst cell nearer square. Edges are not
 * drawn.
 */
final readonly class PatchworkEngine implements LayoutEngine
{
    /** The room an `area` of one takes, in the graph's unit squared: as patchwork measures it. */
    public const float UNIT = 1000.0;

    /**
     * @param array<string, float> $areas each node's area, by id, in the graph's unit squared; {@see self::UNIT} where none is given
     */
    public function __construct(
        private array $areas = [],
    ) {}

    /** @return \Generator<int, null, mixed, Placement> */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $total = $this->area($graph, null);
        $side = \sqrt($total);
        $cells = [];
        $this->fill($graph, null, [0.0, 0.0, $side, $side], $cells);
        yield null;
        $padding = $graph->spacing->padding;
        $nodes = [];
        $endpoints = [];
        $clusters = [];
        foreach ($cells as $key => [$x0, $y0, $x1, $y1]) {
            [$kind, $index] = \explode(':', $key);
            if ('c' === $kind) {
                $layout = $graph->clusters[(int) $index];
                $clusters[] = new GraphCluster($layout->id, $layout->label, $x0 + $padding, $y0 + $padding, $x1 + $padding, $y1 + $padding, null, null);
                continue;
            }
            $layout = $graph->nodes[(int) $index];
            $placed = new GraphNode((int) $index, $layout->id, $layout->label, (($x0 + $x1) / 2) + $padding, (($y0 + $y1) / 2) + $padding, $x1 - $x0, $y1 - $y0, null, null, dashed: false);
            $nodes[(int) $index] = $placed;
            $endpoints[$layout->id] = $placed;
        }
        \ksort($nodes);

        return new Placement(new GraphLayout($side + (2 * $padding), $side + (2 * $padding), $nodes, [], $clusters), $endpoints, ['' => $graph->direction]);
    }

    /** How much room a scope's nodes take, its clusters' with them. */
    private function area(LayoutGraph $graph, ?string $scope): float
    {
        $area = 0.0;
        foreach ($graph->nodes as $layout) {
            $area += $layout->cluster === $scope ? $this->areas[$layout->id] ?? self::UNIT : 0.0;
        }
        foreach ($graph->clusters as $layout) {
            $area += $layout->parent === $scope ? $this->area($graph, $layout->id) : 0.0;
        }

        return $area;
    }

    /**
     * Fills a rectangle with a scope's items, largest first, row by row, each cluster's in turn.
     *
     * @param array{float, float, float, float} $rectangle left, top, right, bottom
     * @param array<string, array{float, float, float, float}> $cells each item's, by `n:` and node or `c:` and cluster
     */
    private function fill(LayoutGraph $graph, ?string $scope, array $rectangle, array &$cells): void
    {
        $items = [];
        foreach ($graph->clusters as $cluster => $layout) {
            if ($layout->parent !== $scope) {
                continue;
            }
            $items[] = ['c:'.$cluster, $this->area($graph, $layout->id), $layout->id];
        }
        foreach ($graph->nodes as $node => $layout) {
            if ($layout->cluster !== $scope) {
                continue;
            }
            $items[] = ['n:'.$node, $this->areas[$layout->id] ?? self::UNIT, null];
        }
        // Largest first; of equal ones, the first to come.
        \usort($items, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $row = [];
        foreach ($items as $item) {
            $short = \min($rectangle[2] - $rectangle[0], $rectangle[3] - $rectangle[1]);
            if ([] !== $row && self::worst([...$row, $item], $short) > self::worst($row, $short)) {
                $rectangle = self::lay($row, $rectangle, $cells);
                $row = [];
            }
            $row[] = $item;
        }
        if ([] !== $row) {
            self::lay($row, $rectangle, $cells);
        }
        foreach ($items as [$key, , $cluster]) {
            if (null === $cluster) {
                continue;
            }
            $this->fill($graph, $cluster, $cells[$key], $cells);
        }
    }

    /**
     * How far from square a row's least square cell would be, laid along a side so long.
     *
     * @param list<array{string, float, ?string}> $row
     */
    private static function worst(array $row, float $side): float
    {
        $areas = \array_map(static fn (array $item): float => $item[1], $row);
        $sum = \array_sum($areas);
        if ($sum <= 0 || $side <= 0) {
            return \INF;
        }
        [$largest, $smallest] = [\max($areas), \max(1e-12, \min($areas))];

        return \max(($side * $side * $largest) / ($sum * $sum), ($sum * $sum) / ($side * $side * $smallest));
    }

    /**
     * Lays a row along the shorter side of a rectangle, as deep as its area asks, and returns what is left.
     *
     * @param list<array{string, float, ?string}> $row
     * @param array{float, float, float, float} $rectangle
     * @param array<string, array{float, float, float, float}> $cells
     * @return array{float, float, float, float}
     */
    private static function lay(array $row, array $rectangle, array &$cells): array
    {
        [$left, $top, $right, $bottom] = $rectangle;
        $sum = \array_sum(\array_map(static fn (array $item): float => $item[1], $row));
        if (($right - $left) >= ($bottom - $top)) {
            // A column down the left.
            $depth = $bottom > $top ? $sum / ($bottom - $top) : 0.0;
            $y = $top;
            foreach ($row as [$key, $area]) {
                $height = $depth > 0 ? $area / $depth : 0.0;
                $cells[$key] = [$left, $y, $left + $depth, $y + $height];
                $y += $height;
            }

            return [$left + $depth, $top, $right, $bottom];
        }
        // A row across the top.
        $depth = $right > $left ? $sum / ($right - $left) : 0.0;
        $x = $left;
        foreach ($row as [$key, $area]) {
            $width = $depth > 0 ? $area / $depth : 0.0;
            $cells[$key] = [$x, $top, $x + $width, $top + $depth];
            $x += $width;
        }

        return [$left, $top + $depth, $right, $bottom];
    }
}
