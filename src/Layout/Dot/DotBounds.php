<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/** A dot layout's bounds, as `dot` is seen to set them: around what it draws. */
final class DotBounds
{
    /**
     * A placement moved to stand its padding in from what it draws, and as big as that, as
     * `dot` is seen to bound a drawing: by its nodes, the loops beside them, its clusters with 8
     * around them, its edges' labels and its edges themselves, out to whole points — not by the
     * room the layout kept around them.
     *
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     */
    public static function bounded(LayoutGraph $graph, DotLayers $layers, Placement $placement, array $edgeIndexes): Placement
    {
        $lines = [...\array_diff_key($placement->routes, $placement->splines), ...$placement->splines];
        foreach ($placement->pieces as $runs) {
            foreach ($runs as $run) {
                $lines[] = $run[0];
            }
        }
        [$dx, $dy, $width, $height] = self::bounds($graph, $layers, $edgeIndexes, $placement->graph->nodes, $placement->graph->clusters, $placement->labels, $lines);
        $nodes = \array_map(static fn (GraphNode $node): GraphNode => $node->at($node->x + $dx, $node->y + $dy), $placement->graph->nodes);
        $endpoints = [];
        foreach ($graph->nodes as $id => $node) {
            $endpoints[$node->id] = $nodes[$id];
        }
        $clusters = \array_map(
            static fn (GraphCluster $cluster): GraphCluster => new GraphCluster(
                $cluster->name,
                $cluster->label,
                $cluster->x0 + $dx,
                $cluster->y0 + $dy,
                $cluster->x1 + $dx,
                $cluster->y1 + $dy,
                null,
                null,
            ),
            $placement->graph->clusters,
        );
        $pieces = \array_map(
            static fn (array $runs): array => \array_map(static fn (array $run): array => [self::line($run[0], $dx, $dy), self::line($run[1], $dx, $dy), $run[2], $run[3]], $runs),
            $placement->pieces,
        );

        return new Placement(
            new GraphLayout($width, $height, $nodes, [], $clusters),
            $endpoints,
            $placement->directions,
            \array_map(static fn (array $points): array => self::line($points, $dx, $dy), $placement->routes),
            self::moved($placement->labels, $dx, $dy),
            \array_map(static fn (array $points): array => self::line($points, $dx, $dy), $placement->splines),
            \array_map(static fn (array $points): array => self::line($points, $dx, $dy), $placement->polylines),
            $pieces,
            \array_map(static fn (array $points): array => self::line($points, $dx, $dy), $placement->controls),
        );
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function line(array $points, float $dx, float $dy): array
    {
        return \array_values(self::moved($points, $dx, $dy));
    }

    /**
     * @template K of array-key
     * @param array<K, array{float, float}> $points
     * @return array<K, array{float, float}>
     */
    private static function moved(array $points, float $dx, float $dy): array
    {
        return \array_map(static fn (array $at): array => [$at[0] + $dx, $at[1] + $dy], $points);
    }

    /**
     * How far to move a drawing to stand its padding in from what it draws, and how big it then
     * is, as `dot` is seen to bound a drawing: by its nodes, the loops beside them, its clusters,
     * its edges' labels and its edges themselves, 8 around its clusters, out to whole points —
     * not by the room the layout kept around them.
     *
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     * @param array<int, GraphNode> $nodes
     * @param list<GraphCluster> $clusters
     * @param array<int, array{float, float}> $labels each label's centre, by original index
     * @param array<array-key, list<array{float, float}>> $lines
     * @return array{float, float, float, float}
     */
    private static function bounds(LayoutGraph $graph, DotLayers $layers, array $edgeIndexes, array $nodes, array $clusters, array $labels, array $lines): array
    {
        $box = [\INF, \INF, -\INF, -\INF];
        $take = static function (float $x0, float $y0, float $x1, float $y1) use (&$box): void {
            $box = [\min($box[0], $x0), \min($box[1], $y0), \max($box[2], $x1), \max($box[3], $y1)];
        };
        $sizes = [];
        foreach ($graph->edges as $edge) {
            $sizes[$edge->index] = [$edge->labelWidth, $edge->labelHeight];
        }
        // Laid out left to right, the loops stand above their node, each keeping its label's height.
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        $loops = [];
        foreach ($layers->loops as [$node, $layered]) {
            $loops[$node] = ($loops[$node] ?? 0.0) + DotLayers::LOOP + ($sizes[$edgeIndexes[$layered]][$turned ? 1 : 0] ?? 0.0);
        }
        foreach ($nodes as $id => $node) {
            [$right, $top] = $turned ? [0.0, $loops[$id] ?? 0.0] : [$loops[$id] ?? 0.0, 0.0];
            $take($node->x - ($node->w / 2), $node->y - ($node->h / 2) - $top, $node->x + ($node->w / 2) + $right, $node->y + ($node->h / 2));
        }
        // A cluster that holds nothing takes no room.
        foreach (\array_filter($clusters, static fn (GraphCluster $cluster): bool => $cluster->x1 > $cluster->x0 && $cluster->y1 > $cluster->y0) as $cluster) {
            $take($cluster->x0 - DotPosition::MARGIN, $cluster->y0 - DotPosition::MARGIN, $cluster->x1 + DotPosition::MARGIN, $cluster->y1 + DotPosition::MARGIN);
        }
        // A label over the nodes between a flat edge's ends reaches, away from its rank, as far from its middle, to the point
        // nearer the rank, as the rank's nodes reach from theirs.
        $beyond = [];
        foreach ($layers->flat as [$tail, $head, $layered, $labelWidth, $labelHeight]) {
            if (($labelWidth <= 0 && $labelHeight <= 0) || !DotVertical::bridging($layers, $tail, $head)) {
                continue;
            }
            $tallest = \max(\array_map(static fn (int $node): float => $layers->height[$node], $layers->ranks[$layers->rank[$tail]]));
            $beyond[$edgeIndexes[$layered]] = [$tail, \max(0.0, ($tallest / 2) - \ceil(($labelHeight / 2) - 1e-9))];
        }
        foreach ($labels as $original => [$x, $y]) {
            [$width, $height] = $sizes[$original] ?? [0.0, 0.0];
            $edges = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
            if (\array_key_exists($original, $beyond)) {
                [$tail, $reach] = $beyond[$original];
                $axis = $turned ? 0 : 1;
                $away = [$x, $y][$axis] < [$nodes[$tail]->x, $nodes[$tail]->y][$axis];
                $edges[$away ? $axis : $axis + 2] += $away ? -$reach : $reach;
            }
            $take(...$edges);
        }
        foreach ($lines as $line) {
            foreach ($line as [$x, $y]) {
                $take($x, $y, $x, $y);
            }
        }
        $padding = $graph->spacing->padding;
        if (!\is_finite($box[0])) {
            return [0.0, 0.0, 2 * $padding, 2 * $padding];
        }
        // As `dot` rounds its bounds: across out to whole points; down from where they start, only its height up to a whole point.
        $box = [\floor($box[0] + 1e-6), $box[1], \ceil($box[2] - 1e-6), $box[3]];

        return [$padding - $box[0], $padding - $box[1], $box[2] - $box[0] + (2 * $padding), \ceil($box[3] - $box[1] - 1e-6) + (2 * $padding)];
    }
}
