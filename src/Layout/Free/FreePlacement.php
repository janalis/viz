<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Free;

use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;

/**
 * A placement for layouts that set each node where they please, as neato and twopi do: the
 * connected parts side by side, each from the left with its boxes aligned at their tops,
 * inside the padding; edges straight between centres, their labels midway; no clusters.
 */
final class FreePlacement
{
    /** The space between two parts laid side by side, in the graph's unit. */
    private const float APART = 18.0;

    /**
     * Parts laid out on their own, moved to stand side by side.
     *
     * @param list<array<int, array{float, float}>> $parts each part's centres, by node
     * @return array<int, array{float, float}>
     */
    public static function sideBySide(LayoutGraph $graph, array $parts): array
    {
        $positions = [];
        $cursor = 0.0;
        foreach ($parts as $placed) {
            [$left, $top, $right] = self::extent($placed, $graph);
            foreach ($placed as $node => [$x, $y]) {
                $positions[$node] = [$x - $left + $cursor, $y - $top];
            }
            $cursor += $right - $left + self::APART;
        }

        return $positions;
    }

    /**
     * How far a part's boxes reach: left, top, right, bottom.
     *
     * @param array<int, array{float, float}> $positions
     * @return array{float, float, float, float}
     */
    private static function extent(array $positions, LayoutGraph $graph): array
    {
        [$left, $top, $right, $bottom] = [\INF, \INF, -\INF, -\INF];
        foreach ($positions as $node => [$x, $y]) {
            $box = $graph->nodes[$node];
            $left = \min($left, $x - ($box->width / 2));
            $top = \min($top, $y - ($box->height / 2));
            $right = \max($right, $x + ($box->width / 2));
            $bottom = \max($bottom, $y + ($box->height / 2));
        }

        return [$left, $top, $right, $bottom];
    }

    /** @param array<int, array{float, float}> $positions */
    public static function of(LayoutGraph $graph, array $positions): Placement
    {
        $padding = $graph->spacing->padding;
        $nodes = [];
        $endpoints = [];
        [$width, $height] = [0.0, 0.0];
        foreach ($graph->nodes as $id => $node) {
            [$x, $y] = $positions[$id];
            $placed = new GraphNode($id, $node->id, $node->label, $x + $padding, $y + $padding, $node->width, $node->height, null, null, dashed: false);
            $nodes[$id] = $placed;
            $endpoints[$node->id] = $placed;
            $width = \max($width, $x + ($node->width / 2) + (2 * $padding));
            $height = \max($height, $y + ($node->height / 2) + (2 * $padding));
        }
        $routes = [];
        $labels = [];
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$endpoints[$edge->from] ?? null, $endpoints[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $routes[$edge->index] = [[$tail->x, $tail->y], [$head->x, $head->y]];
            if ($edge->labelWidth > 0 || $edge->labelHeight > 0) {
                $labels[$edge->index] = [($tail->x + $head->x) / 2, ($tail->y + $head->y) / 2];
            }
        }

        return new Placement(new GraphLayout($width, $height, $nodes, [], []), $endpoints, ['' => $graph->direction], $routes, $labels);
    }
}
