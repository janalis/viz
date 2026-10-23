<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Source\Unsupported;

/**
 * dot's layout, after Gansner, Koutsofios, North and Vo: ranks by network simplex, clusters
 * ranked on their own; virtual nodes along edges that span ranks; orders that cross few
 * edges; coordinates by network simplex on an auxiliary graph. In points. A left-to-right
 * graph is laid out top-down with every size turned, then turned back. Each edge's route is
 * the polyline through its virtual nodes, from centre to centre, for a drawing to smooth and
 * clip. A graph whose edges would need more virtual nodes than {@see MAX_VIRTUAL} is refused
 * as {@see Unsupported}.
 */
final readonly class DotEngine implements LayoutEngine
{
    /** The most virtual nodes a layout may need: past it, the graph is too large to lay out here. */
    public const int MAX_VIRTUAL = 20_000;

    /**
     * @return \Generator<int, null, mixed, Placement>
     * @throws Unsupported when the layout needs more virtual nodes than {@see self::MAX_VIRTUAL}
     */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        $index = [];
        $sizes = [];
        foreach ($graph->nodes as $position => $node) {
            $index[$node->id] = $position;
            $sizes[] = $turned ? [$node->height, $node->width] : [$node->width, $node->height];
        }
        $clusterIndex = [];
        foreach ($graph->clusters as $position => $cluster) {
            $clusterIndex[$cluster->id] = $position;
        }
        $owner = \array_map(static fn ($node): int => null === $node->cluster ? -1 : $clusterIndex[$node->cluster] ?? -1, $graph->nodes);
        $parents = \array_map(static fn ($cluster): int => null === $cluster->parent ? -1 : $clusterIndex[$cluster->parent] ?? -1, $graph->clusters);
        $labelled = [] !== \array_filter($graph->edges, static fn ($edge): bool => $edge->labelWidth > 0 || $edge->labelHeight > 0);
        // With edge labels, every edge spans twice the ranks, a label taking the rank between.
        $factor = $labelled ? 2 : 1;
        $constraints = [];
        foreach ($graph->edges as $edge) {
            if (!$edge->constraint || !\array_key_exists($edge->from, $index) || !\array_key_exists($edge->to, $index)) {
                continue;
            }
            $constraints[] = [$index[$edge->from], $index[$edge->to], $edge->minLength * $factor, $edge->weight];
        }
        $sets = [];
        foreach ($graph->rankSets as $set) {
            $members = [];
            foreach ($set->nodes as $id) {
                if (!\array_key_exists($id, $index)) {
                    continue;
                }
                $members[] = $index[$id];
            }
            $sets[] = [$set->kind, $members];
        }
        $ranking = new DotRanking(\count($graph->nodes), $constraints, $owner, $parents, $sets, [] === $graph->clusters ? NetworkSimplex::BALANCE_RANKS : NetworkSimplex::BALANCE_NONE);
        $work = $ranking->ranks();
        yield from $work;
        $ranks = $work->getReturn();
        $edges = [];
        $labelSizes = [];
        foreach ($graph->edges as $edge) {
            if (!\array_key_exists($edge->from, $index) || !\array_key_exists($edge->to, $index)) {
                continue;
            }
            [$labelWidth, $labelHeight] = $turned ? [$edge->labelHeight, $edge->labelWidth] : [$edge->labelWidth, $edge->labelHeight];
            $edges[$edge->index] = [$index[$edge->from], $index[$edge->to], $edge->weight, $labelWidth, $labelHeight, $edge->tailPort, $edge->headPort];
            if ($labelWidth > 0 || $labelHeight > 0) {
                $labelSizes[$edge->index] = [$labelWidth, $labelHeight];
            }
        }
        $edges = self::met($edges, $owner);
        $span = 0;
        foreach ($edges as [$tail, $head]) {
            $span += \max(0, \abs($ranks[$tail] - $ranks[$head]) - 1);
        }
        if ($span > self::MAX_VIRTUAL) {
            throw new Unsupported('The graph is too large to lay out natively: its edges span '.$span.' ranks');
        }
        $layers = new DotLayers(
            $ranks,
            $sizes,
            $owner,
            $parents,
            \array_values($edges),
            $graph->spacing->nodeGap * DotSplines::SPREAD,
            $graph->concentrate ? Concentration::Merged : Concentration::Apart,
            $turned,
        );
        $constrained = \array_column(\array_map(static fn ($edge): array => [$edge->index, $edge->constraint], $graph->edges), 1, 0);
        $loose = \array_keys(\array_filter(\array_keys($edges), static fn (int $original): bool => !$constrained[$original]));
        $order = new DotOrdering(
            $layers,
            turned: \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true),
            loose: $loose,
            kept: self::ordered($graph, $layers),
        )->order();
        yield from $order;
        // Laid out top-down to be turned left to right, a cluster keeps its label's band on its left, which becomes its top.
        $clusterLabels = \array_map(static fn ($cluster): array => $turned ? [0.0, 0.0] : [$cluster->minWidth, $cluster->labelHeight], $graph->clusters);
        $bands = \array_map(static fn ($cluster): float => $turned ? $cluster->labelHeight : 0.0, $graph->clusters);
        // Without labels, ranks stand their separation apart to the nearest point, as `dot` is seen to set them.
        $rankSeparation = $labelled ? $graph->spacing->rankGap / 2 : \round($graph->spacing->rankGap);
        $position = new DotPosition($layers, $clusterLabels, $graph->spacing->nodeGap, $rankSeparation, $graph->spacing->padding, $bands);
        $placing = $position->place();
        yield from $placing;
        self::stretch($graph, $position);

        return $this->placement($graph, $layers, $position, \array_keys($edges), $labelSizes);
    }

    /**
     * As dot's `ratio`, positions stretched until the drawing is as tall for its width as
     * asked: down where it is too flat, else across; laid out to be turned, the other way.
     */
    private static function stretch(LayoutGraph $graph, DotPosition $position): void
    {
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        $padding = $graph->spacing->padding;
        [$width, $height] = [$position->width - (2 * $padding), $position->height - (2 * $padding)];
        [$width, $height] = $turned ? [$height, $width] : [$width, $height];
        if (null === $graph->aspect || $graph->aspect <= 0 || $width <= 0 || $height <= 0) {
            return;
        }
        $wanted = $graph->aspect * $width;
        [$across, $down] = $height < $wanted ? [1.0, $wanted / $height] : [$height / $wanted, 1.0];
        if ($turned) {
            $position->stretch($down, $across);

            return;
        }
        $position->stretch($across, $down);
    }

    /**
     * As dot's `ordering`, the order a node keeps its edges in: the next node along each of its
     * edges out, or back along each of its edges in, on the rank beside it, each before the next.
     *
     * @return list<array{int, int}> pairs of nodes, the first kept before the second
     */
    private static function ordered(LayoutGraph $graph, DotLayers $layers): array
    {
        $kept = [];
        foreach ($graph->nodes as $node => $layout) {
            if (null === $layout->ordering) {
                continue;
            }
            $out = 'out' === $layout->ordering;
            $next = [];
            foreach ($layers->paths as $path) {
                $end = $out ? $path : \array_reverse($path);
                if ($end[0] !== $node || $layers->rank[$end[1]] !== ($layers->rank[$node] + ($out ? 1 : -1))) {
                    continue;
                }
                $next[$end[1]] = true;
            }
            $next = \array_keys($next);
            for ($index = 1, $count = \count($next); $index < $count; ++$index) {
                $kept[] = [$next[$index - 1], $next[$index]];
            }
        }

        return $kept;
    }

    /**
     * How far across each edge between ranks runs from the path it shares: edges sharing one
     * spread evenly about it, in the order they were written.
     *
     * @return array<int, float> by layered edge
     */
    private static function spread(DotLayers $layers, float $apart): array
    {
        $sharing = [];
        foreach ($layers->paths as $edge => $path) {
            $top = $layers->rank[$path[0]] < $layers->rank[$path[\count($path) - 1]] ? $path : \array_reverse($path);
            $sharing[\implode(',', $top)][] = $edge;
        }
        $offsets = [];
        foreach ($sharing as $edges) {
            foreach ($edges as $place => $edge) {
                $offsets[$edge] = ($place - ((\count($edges) - 1) / 2)) * $apart;
            }
        }

        return $offsets;
    }

    /**
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     * @param array<int, array{float, float}> $labelSizes each labelled edge's label, by original index, as wide and tall as it is laid out
     */
    private function placement(LayoutGraph $graph, DotLayers $layers, DotPosition $position, array $edgeIndexes, array $labelSizes): Placement
    {
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        $width = $turned ? $position->height : $position->width;
        $height = $turned ? $position->width : $position->height;
        $point = static function (float $x, float $y) use ($graph, $turned, $width, $height): array {
            [$x, $y] = $turned ? [$y, $x] : [$x, $y];

            return match ($graph->direction) {
                Direction::BottomTop => [$x, $height - $y],
                Direction::RightLeft => [$width - $x, $y],
                default => [$x, $y],
            };
        };
        $nodes = [];
        $endpoints = [];
        foreach ($graph->nodes as $id => $node) {
            [$x, $y] = $point($position->x[$id], $position->y[$layers->rank[$id]]);
            $placed = new GraphNode($id, $node->id, $node->label, $x, $y, $node->width, $node->height, null, null, dashed: false);
            $nodes[$id] = $placed;
            $endpoints[$node->id] = $placed;
        }
        $clusters = [];
        foreach ($graph->clusters as $index => $cluster) {
            [$left, $top, $right, $bottom] = $position->boxes[$index];
            [$x0, $y0] = $point($left, $top);
            [$x1, $y1] = $point($right, $bottom);
            $clusters[] = new GraphCluster($cluster->id, $cluster->label, \min($x0, $x1), \min($y0, $y1), \max($x0, $x1), \max($y0, $y1), null, null);
        }
        $routes = [];
        $labels = [];
        $splines = [];
        $polylines = [];
        $router = new DotSplines($layers, $position, $graph->spacing->nodeGap, \array_map(static fn ($node): Shape => $node->shape, $graph->nodes));
        // Merged, edges between the same two nodes are one line, none spread.
        $offsets = self::spread($layers, $graph->concentrate ? 0.0 : $graph->spacing->nodeGap * DotSplines::SPREAD);
        foreach ($edgeIndexes as $layered => $original) {
            $path = $layers->paths[$layered] ?? null;
            if (null !== $path) {
                $routes[$original] = \array_map(static fn (int $node): array => $point($position->x[$node], $position->y[$layers->rank[$node]]), $path);
                [$spline, $corners] = $router->route($path, $offsets[$layered]);
                $splines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $spline);
                $polylines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $corners);
            }
            $label = self::label($layers, $position, $router, $layered, $labelSizes[$original] ?? null);
            if (null !== $label) {
                $labels[$original] = $point(...$label);
            }
        }
        foreach ($layers->flat as [$tail, $head, $layered, $labelWidth, $labelHeight]) {
            $routes[$edgeIndexes[$layered]] = [$point($position->x[$tail], $position->y[$layers->rank[$tail]]), $point($position->x[$head], $position->y[$layers->rank[$head]])];
            if ($labelWidth > 0 || $labelHeight > 0) {
                $labels[$edgeIndexes[$layered]] = $point(...self::flatLabel($layers, $position, $tail, $head, $labelHeight));
            }
        }

        $pieces = [];
        if ($graph->concentrate) {
            foreach (self::pieces($layers, $edgeIndexes) as $original => $runs) {
                $pieces[$original] = \array_map(static function (array $run) use ($router, $point): array {
                    [$spline, $corners] = $router->route($run[0]);

                    return [
                        \array_map(static fn (array $at): array => $point($at[0], $at[1]), $spline),
                        \array_map(static fn (array $at): array => $point($at[0], $at[1]), $corners),
                        $run[1],
                        $run[2],
                    ];
                }, $runs);
            }
        }

        $placement = new Placement(new GraphLayout($width, $height, $nodes, [], $clusters), $endpoints, ['' => $graph->direction], $routes, $labels, $splines, $polylines, $pieces);

        // Stretched to an aspect, a drawing keeps the bounds it was stretched to.
        return null !== $graph->aspect && $graph->aspect > 0 ? $placement : DotBounds::bounded($graph, $layers, $placement);
    }

    /**
     * Where a layered edge's label stands, as laid out before it is turned: right of its label
     * node; or, on an edge to the next rank, which has no rank between for one, hung by its top
     * right corner from its spline's middle, as `dot` is seen to hang it.
     *
     * @param ?array{float, float} $size the label's width and height as laid out, null for none
     * @return ?array{float, float} null for an edge without a label
     */
    private static function label(DotLayers $layers, DotPosition $position, DotSplines $router, int $layered, ?array $size): ?array
    {
        $node = $layers->labels[$layered] ?? null;
        if (null !== $node) {
            return [$layers->mirrored ? $position->x[$node] - ($layers->left[$node] / 2) : $position->x[$node] + ($layers->right[$node] / 2), $position->y[$layers->rank[$node]]];
        }
        $path = $layers->paths[$layered] ?? null;
        if (null === $path || null === $size) {
            return null;
        }
        [$x, $y] = $router->middle($path);

        return [$x - ($size[0] / 2), $y + ($size[1] / 2)];
    }

    /**
     * Where a flat edge's label stands, as `dot` is seen to set it: midway between its ends'
     * facing sides, half its height and 3 more above the edge.
     *
     * @return array{float, float}
     */
    private static function flatLabel(DotLayers $layers, DotPosition $position, int $tail, int $head, float $height): array
    {
        [$left, $right] = $position->x[$tail] <= $position->x[$head] ? [$tail, $head] : [$head, $tail];

        return [($position->x[$left] + $layers->right[$left] + $position->x[$right] - $layers->left[$right]) / 2, $position->y[$layers->rank[$tail]] - ($height / 2) - 3];
    }

    /**
     * The edges in the order the ordering meets them, as dot meets them: node by node, each node's
     * out-edges in turn. `dot` is seen to set a node's children in its own cluster first, then
     * the rest, each left to right in the order they were made, whatever order its edges are
     * written in.
     *
     * @template T of array{int, int, mixed, mixed, mixed, mixed, mixed}
     * @param array<int, T> $edges by original index
     * @param list<int> $owner each node's cluster, -1 at the top
     * @return array<int, T>
     */
    private static function met(array $edges, array $owner): array
    {
        $outside = static fn (array $edge): int => (int) (-1 === $owner[$edge[0]] || $owner[$edge[0]] !== $owner[$edge[1]]);
        \uksort($edges, static fn (int $a, int $b): int => [$edges[$a][0], $outside($edges[$a]), $edges[$a][1], $a] <=> [$edges[$b][0], $outside($edges[$b]), $edges[$b][1], $b]);

        return $edges;
    }

    /**
     * As dot draws merged edges, each stretch between ranks once: by the first edge along it.
     * Each edge's own stretches, in runs broken where chains branch, and whether a run starts
     * at its tail and ends at its head.
     *
     * @param list<int> $edgeIndexes
     * @return array<int, list<array{list<int>, bool, bool}>> by original edge
     */
    private static function pieces(DotLayers $layers, array $edgeIndexes): array
    {
        // Where merged chains branch, at a node more than two stretches meet, each part ends, so they join there.
        $stretches = [];
        foreach ($layers->paths as $path) {
            for ($index = 1, $count = \count($path); $index < $count; ++$index) {
                $stretches[$path[$index - 1]][$path[$index]] = true;
                $stretches[$path[$index]][$path[$index - 1]] = true;
            }
        }
        $drawn = [];
        $pieces = [];
        foreach ($edgeIndexes as $layered => $original) {
            $path = $layers->paths[$layered] ?? null;
            if (null === $path) {
                continue;
            }
            $pieces[$original] = [];
            // The run of stretches this edge draws, from one place along its path to another; none at -1.
            [$from, $to] = [-1, 0];
            $last = \count($path) - 1;
            for ($index = 1; $index <= $last; ++$index) {
                $key = \min($path[$index - 1], $path[$index]).'-'.\max($path[$index - 1], $path[$index]);
                $own = !\array_key_exists($key, $drawn);
                $drawn[$key] = true;
                if ($own) {
                    [$from, $to] = [-1 === $from ? $index - 1 : $from, $index];
                }
                if (-1 !== $from && (!$own || $index === $last || \count($stretches[$path[$index]]) > 2)) {
                    $pieces[$original][] = [\array_slice($path, $from, $to - $from + 1), 0 === $from, $last === $to];
                    $from = -1;
                }
            }
        }

        return $pieces;
    }
}
