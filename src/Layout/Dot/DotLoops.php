<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Curve\CurvePath;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/**
 * A graph's loops, as `dot` is seen to draw them on a node's right, or above it laid out left to
 * right: each a curve from the node's centre out to its reach, a node separation beyond the loop
 * before it, or that loop's label where wider, held a third of the way out and at its reach as far
 * aside as it bulges — first up, down laid out bottom up, left laid out left to right, right laid
 * out right to left — then back as far the other way; the i-th of n bulging i/n of a quarter of
 * the node's side along it; its label just past its reach.
 */
final readonly class DotLoops
{
    /**
     * @param array<int, GraphNode> $nodes the placed nodes, by layer node
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     * @return array{array<int, list<array{float, float}>>, array<int, array{float, float}>, array<int, list<array{float, float}>>} each loop's curve, its label's centre, and its curve's control points, by original edge
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
        [$curves, $centres, $controls] = [[], [], []];
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        // Which way it reaches out, and which way it bulges first.
        [$out, $side] = match ($graph->direction) {
            Direction::TopBottom => [[1, 0], [0, -1]],
            Direction::BottomTop => [[1, 0], [0, 1]],
            Direction::LeftRight => [[0, -1], [-1, 0]],
            Direction::RightLeft => [[0, -1], [1, 0]],
        };
        foreach ($layers->loops as [$node, $layered]) {
            $original = $edgeIndexes[$layered];
            $placed = $nodes[$node];
            $seen[$node] = ($seen[$node] ?? 0) + 1;
            [$across, $along] = $turned ? [$placed->h, $placed->w] : [$placed->w, $placed->h];
            $reach = ($across / 2) + ($reached[$node] ?? 0.0) + $graph->spacing->nodeGap;
            $controls[$original] = self::controls([$placed->x, $placed->y], $out, $side, $reach, (($along / 4) * $seen[$node]) / $counts[$node]);
            $curves[$original] = self::curve($controls[$original]);
            [$labelWidth, $labelHeight] = $labels[$original] ?? [0.0, 0.0];
            $beyond = $turned ? $labelHeight : $labelWidth;
            if ($labelWidth > 0 || $labelHeight > 0) {
                $centres[$original] = [$placed->x + ($out[0] * ($reach + ($beyond / 2))), $placed->y + ($out[1] * ($reach + ($beyond / 2)))];
            }
            $reached[$node] = ($reached[$node] ?? 0.0) + \max($graph->spacing->nodeGap, $beyond);
        }

        return [$curves, $centres, $controls];
    }

    /**
     * @param array{float, float} $centre
     * @param array{int, int} $out
     * @param array{int, int} $side
     * @return list<array{float, float}> its start, then two controls and an end for each of its two curves
     */
    private static function controls(array $centre, array $out, array $side, float $reach, float $bulge): array
    {
        $at = static fn (float $outward, float $aside): array => [$centre[0] + ($out[0] * $outward) + ($side[0] * $aside), $centre[1] + ($out[1] * $outward) + ($side[1] * $aside)];

        return [$centre, $at($reach / 3, $bulge), $at($reach, $bulge), $at($reach, aside: 0.0), $at($reach, -$bulge), $at($reach / 3, -$bulge), $centre];
    }

    /**
     * @param list<array{float, float}> $controls
     * @return list<array{float, float}>
     */
    private static function curve(array $controls): array
    {
        $path = new CurvePath($controls[0]);
        $path->cubic($controls[1], $controls[2], $controls[3]);
        $path->cubic($controls[4], $controls[5], $controls[6]);

        return $path->points;
    }
}
