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
        foreach ($graph->edges as $edge) {
            if (!\array_key_exists($edge->from, $index) || !\array_key_exists($edge->to, $index)) {
                continue;
            }
            [$labelWidth, $labelHeight] = $turned ? [$edge->labelHeight, $edge->labelWidth] : [$edge->labelWidth, $edge->labelHeight];
            $edges[$edge->index] = [$index[$edge->from], $index[$edge->to], $edge->weight, $labelWidth, $labelHeight, $edge->tailPort, $edge->headPort];
        }
        // As dot, the ordering meets edges node by node, each node's out-edges in turn.
        \uksort($edges, static fn (int $a, int $b): int => [$edges[$a][0], $a] <=> [$edges[$b][0], $b]);
        $span = 0;
        foreach ($edges as [$tail, $head]) {
            $span += \max(0, \abs($ranks[$tail] - $ranks[$head]) - 1);
        }
        if ($span > self::MAX_VIRTUAL) {
            throw new Unsupported('The graph is too large to lay out natively: its edges span '.$span.' ranks');
        }
        $layers = new DotLayers($ranks, $sizes, $owner, $parents, \array_values($edges), $graph->spacing->nodeGap * DotSplines::SPREAD);
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
        $rankSeparation = $labelled ? $graph->spacing->rankGap / 2 : $graph->spacing->rankGap;
        $position = new DotPosition($layers, $clusterLabels, $graph->spacing->nodeGap, $rankSeparation, $graph->spacing->padding, $bands);
        $placing = $position->place();
        yield from $placing;

        return $this->placement($graph, $layers, $position, \array_keys($edges));
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

    /** @param list<int> $edgeIndexes the original index of each edge the layers know, in their order */
    private function placement(LayoutGraph $graph, DotLayers $layers, DotPosition $position, array $edgeIndexes): Placement
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
        $router = new DotSplines($layers, $position, $graph->spacing->nodeGap);
        $offsets = self::spread($layers, $graph->spacing->nodeGap * DotSplines::SPREAD);
        foreach ($edgeIndexes as $layered => $original) {
            $path = $layers->paths[$layered] ?? null;
            if (null !== $path) {
                $routes[$original] = \array_map(static fn (int $node): array => $point($position->x[$node], $position->y[$layers->rank[$node]]), $path);
                $splines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $router->route($path, $offsets[$layered]));
            }
            $label = $layers->labels[$layered] ?? null;
            if (null !== $label) {
                $labels[$original] = $point($position->x[$label] + $layers->left[$label] + ($layers->right[$label] / 2), $position->y[$layers->rank[$label]]);
            }
        }
        foreach ($layers->flat as [$tail, $head, $layered]) {
            $routes[$edgeIndexes[$layered]] = [$point($position->x[$tail], $position->y[$layers->rank[$tail]]), $point($position->x[$head], $position->y[$layers->rank[$head]])];
        }

        return new Placement(new GraphLayout($width, $height, $nodes, [], $clusters), $endpoints, ['' => $graph->direction], $routes, $labels, $splines);
    }
}
