<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Curve\CurvePath;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\GraphNode;

/**
 * A graph's loops, as `dot` is seen to draw them on a node's right: each a curve from the node's
 * centre out to its reach, a node separation beyond the loop before it and that loop's label,
 * held a third of the way out and at its reach as far up as it bulges, then back as far down —
 * the i-th of n bulging i/n of a quarter of the node's height — its label just past its reach.
 */
final readonly class DotLoops
{
    /**
     * @param array<int, GraphNode> $nodes the placed nodes, by layer node
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     * @return array{array<int, list<array{float, float}>>, array<int, array{float, float}>} each loop's curve and its label's centre, by original edge
     */
    public static function place(LayoutGraph $graph, DotLayers $layers, array $nodes, array $edgeIndexes): array
    {
        $labels = [];
        foreach ($graph->edges as $edge) {
            $labels[$edge->index] = [$edge->labelWidth, $edge->labelHeight];
        }
        $counts = \array_count_values(\array_column($layers->loops, 0));
        $reached = [];
        $seen = [];
        [$curves, $centres] = [[], []];
        foreach ($layers->loops as [$node, $layered]) {
            $original = $edgeIndexes[$layered];
            $placed = $nodes[$node];
            $seen[$node] = ($seen[$node] ?? 0) + 1;
            $at = $seen[$node];
            $reach = ($placed->w / 2) + ($reached[$node] ?? 0.0) + $graph->spacing->nodeGap;
            $curves[$original] = self::curve($placed->x, $placed->y, $reach, (($placed->h / 4) * $at) / $counts[$node]);
            [$labelWidth, $labelHeight] = $labels[$original] ?? [0.0, 0.0];
            if ($labelWidth > 0 || $labelHeight > 0) {
                $centres[$original] = [$placed->x + $reach + ($labelWidth / 2), $placed->y];
            }
            $reached[$node] = ($reached[$node] ?? 0.0) + $graph->spacing->nodeGap + $labelWidth;
        }

        return [$curves, $centres];
    }

    /** @return list<array{float, float}> */
    private static function curve(float $x, float $y, float $reach, float $bulge): array
    {
        [$out, $third] = [$x + $reach, $x + ($reach / 3)];
        $path = new CurvePath([$x, $y]);
        $path->cubic([$third, $y - $bulge], [$out, $y - $bulge], [$out, $y]);
        $path->cubic([$out, $y + $bulge], [$third, $y + $bulge], [$x, $y]);

        return $path->points;
    }
}
