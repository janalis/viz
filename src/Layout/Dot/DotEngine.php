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
        $ranking = DotRanking::of($graph, $constraints, $owner, $parents, $sets);
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
            // An edge that takes no part in ranking keeps its room across the ranks but, as `dot` is seen to, pulls nothing level.
            $edges[$edge->index] = [$index[$edge->from], $index[$edge->to], $edge->constraint ? $edge->weight : 0, $labelWidth, $labelHeight, $edge->tailPort, $edge->headPort, $edge->minLength];
            if ($labelWidth > 0 || $labelHeight > 0) {
                $labelSizes[$edge->index] = [$labelWidth, $labelHeight];
            }
        }
        $edges = DotMeeting::order($edges, $owner, $ranks);
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
            // Each edge after the first sharing a chain widens its virtual nodes by a node separation, as `dot` is seen to.
            $graph->spacing->nodeGap,
            $graph->concentrate ? Concentration::Merged : Concentration::Apart,
            $turned,
        );
        $constrained = \array_column(\array_map(static fn ($edge): array => [$edge->index, $edge->constraint], $graph->edges), 1, 0);
        $loose = \array_keys(\array_filter(\array_keys($edges), static fn (int $original): bool => !$constrained[$original]));
        $order = new DotOrdering(
            $layers,
            turned: \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true),
            loose: $loose,
            kept: self::ordered($graph, $layers, \array_keys($edges)),
        )->order();
        yield from $order;
        // Laid out top-down to be turned left to right, a cluster keeps its label's band on its left, which becomes its top.
        $clusterLabels = \array_map(static fn ($cluster): array => $turned ? [0.0, 0.0] : [$cluster->minWidth, $cluster->labelHeight], $graph->clusters);
        $bands = \array_map(static fn ($cluster): float => $turned ? $cluster->labelHeight : 0.0, $graph->clusters);
        // Without labels, ranks stand their separation apart to the nearest point, as `dot` is seen to set them.
        $rankSeparation = $labelled ? $graph->spacing->rankGap / 2 : \round($graph->spacing->rankGap);
        // Turned, a cluster's label runs down its ranks, which its width then keeps apart.
        $lengths = \array_map(static fn ($cluster): float => $turned ? $cluster->minWidth : 0.0, $graph->clusters);
        $position = new DotPosition($layers, $clusterLabels, $graph->spacing->nodeGap, $rankSeparation, $graph->spacing->padding, $bands, $lengths);
        $placing = $position->place();
        yield from $placing;
        $frame = DotAspect::stretch($graph, $layers, $position);

        return $this->placement($graph, $layers, $position, \array_keys($edges), $labelSizes, $frame);
    }

    /**
     * The flat edges' routes, straight from end to end, and the labels of those labelled; as dot is
     * seen to, one with nodes between its ends goes over them.
     *
     * @param \Closure(float, float): array{float, float} $point
     * @param list<int> $edgeIndexes
     * @param array<int, list<array{float, float}>> $routes
     * @param array<int, list<array{float, float}>> $splines
     * @param array<int, array{float, float}> $labels
     * @return array{array<int, list<array{float, float}>>, array<int, list<array{float, float}>>, array<int, array{float, float}>, array<int, list<array{float, float}>>}
     */
    private static function flat(DotLayers $layers, DotPosition $position, \Closure $point, array $edgeIndexes, array $routes, array $splines, array $labels): array
    {
        $controls = [];
        // Each flat edge's place among those between the same two nodes, in the order written, and how many there are.
        [$places, $counts] = [[], []];
        foreach ($layers->flat as $index => [$tail, $head, , $labelWidth, $labelHeight]) {
            if ($labelWidth > 0 || $labelHeight > 0) {
                continue;
            }
            $pair = \min($tail, $head).':'.\max($tail, $head);
            $counts[$pair] = ($counts[$pair] ?? 0) + 1;
            $places[$index] = [$pair, $counts[$pair]];
        }
        $stacked = self::stacked($layers, $position, $edgeIndexes);
        foreach ($layers->flat as $index => [$tail, $head, $layered, $labelWidth, $labelHeight]) {
            $original = $edgeIndexes[$layered];
            $routes[$original] = [$point($position->x[$tail], $position->y[$layers->rank[$tail]]), $point($position->x[$head], $position->y[$layers->rank[$head]])];
            if ($labelWidth > 0 || $labelHeight > 0) {
                $y = $position->y[$layers->rank[$tail]];
                [$centre, $around, $room] = \array_key_exists($index, $stacked) ? [...$stacked[$index], \INF] : self::bridged($layers, $position, $tail, $head, $labelWidth, $labelHeight);
                $labels[$original] = $point(...$centre);
                if (null === $around) {
                    continue;
                }
                // Round a label, from the corner nearer its tail.
                [$first, $last] = $position->x[$head] >= $position->x[$tail] ? $around : [$around[1], $around[0]];
                $arc = DotArcs::through([$position->x[$tail], $y], [$position->x[$head], $y], $first, $last, [$first, $last], $room, labelled: \INF !== $room && !$layers->mirrored);
                $splines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[0]);
                $routes[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[1]);
                $controls[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[2]);
                continue;
            }
            [$pair, $k] = $places[$index];
            $arc = DotArcs::route($layers, $position, $tail, $head, $k, $counts[$pair]);
            if (null === $arc) {
                continue;
            }
            $splines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[0]);
            $routes[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[1]);
            $controls[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $arc[2]);
        }

        return [$routes, $splines, $labels, $controls];
    }

    /**
     * As dot's `ordering`, the order a node keeps its edges in: the next node along each of its
     * edges out, or back along each of its edges in, on the rank beside it, each before the next.
     *
     * @param list<int> $written each layered edge's index as written
     * @return list<array{int, int}> pairs of nodes, the first kept before the second
     */
    private static function ordered(LayoutGraph $graph, DotLayers $layers, array $written): array
    {
        // The edges as they were written, not as the ordering meets them.
        $paths = [];
        foreach ($layers->paths as $edge => $path) {
            $paths[$written[$edge]] = $path;
        }
        \ksort($paths);
        $kept = [];
        foreach ($graph->nodes as $node => $layout) {
            if (null === $layout->ordering) {
                continue;
            }
            $out = 'out' === $layout->ordering;
            $next = [];
            foreach ($paths as $path) {
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
     * @param list<array{float, float}> $frame the corners of the box stretched to an aspect, to bound the drawing by
     */
    private function placement(LayoutGraph $graph, DotLayers $layers, DotPosition $position, array $edgeIndexes, array $labelSizes, array $frame): Placement
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
        [$nodes, $endpoints, $clusters] = self::placed($graph, $layers, $position, $point);
        $routes = [];
        $labels = [];
        $splines = [];
        $polylines = [];
        $controls = [];
        $router = new DotSplines($layers, $position, $graph->spacing->nodeGap, \array_map(static fn ($node): Shape => $node->shape, $graph->nodes));
        // Merged, edges between the same two nodes are one line, none spread.
        $offsets = self::spread($layers, $graph->concentrate ? 0.0 : $graph->spacing->nodeGap * DotSplines::SPREAD);
        // As `dot` is seen to route them: the shortest from end to end first, of edges as long those with a label node first.
        $routing = $router->order($edgeIndexes, \array_intersect_key($edgeIndexes, $layers->labels));
        foreach ($routing as $layered => $original) {
            $path = $layers->paths[$layered] ?? null;
            $spline = [];
            if (null !== $path) {
                $routes[$original] = \array_map(static fn (int $node): array => $point($position->x[$node], $position->y[$layers->rank[$node]]), $path);
                [$spline, $corners, $curve] = $router->route($path, $offsets[$layered]);
                $splines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $spline);
                if ([] !== $curve) {
                    $controls[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $curve);
                }
                $polylines[$original] = \array_map(static fn (array $at): array => $point($at[0], $at[1]), $corners);
            }
            $label = self::label($layers, $position, $router, $layered, $labelSizes[$original] ?? null, $spline);
            if (null !== $label) {
                $labels[$original] = $point(...$label);
            }
        }
        // Kept in the edges' own order, whatever order they were routed in.
        $order = \array_values($edgeIndexes);
        $routes = self::inOrder($routes, $order);
        $splines = self::inOrder($splines, $order);
        $polylines = self::inOrder($polylines, $order);
        $controls = self::inOrder($controls, $order);
        $labels = self::inOrder($labels, $order);
        [$routes, $splines, $labels, $arcs] = self::flat($layers, $position, $point, $edgeIndexes, $routes, $splines, $labels);
        $controls = $arcs + $controls;

        $pieces = [];
        if ($graph->concentrate) {
            // Merged runs are routed once every label is drawn: none may keep clear of a label
            // drawn after its own edge's first route, so they keep their label nodes' room alone.
            $router->drawn = [];
            $router->back = [];
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

        [$loops, $loopLabels, $loopControls] = DotLoops::place($graph, $layers, $position, $nodes, $edgeIndexes);
        $controls += $loopControls;
        $splines += $loops;
        $routes += $loops;
        $labels += $loopLabels;
        $placement = new Placement(new GraphLayout($width, $height, $nodes, [], $clusters), $endpoints, ['' => $graph->direction], $routes, $labels, $splines, $polylines, $pieces, $controls);

        // Stretched to an aspect, a drawing takes at least the box it was stretched to.
        return DotBounds::bounded($graph, $layers, $placement, $edgeIndexes, \array_map(static fn (array $at): array => $point($at[0], $at[1]), $frame));
    }

    /**
     * The nodes and clusters where the layout set them, turned as the graph's direction asks.
     *
     * @param \Closure(float, float): array{float, float} $point
     * @return array{array<int, GraphNode>, array<string, GraphNode>, list<GraphCluster>} the nodes by layout index and by name, and the clusters
     */
    private static function placed(LayoutGraph $graph, DotLayers $layers, DotPosition $position, \Closure $point): array
    {
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
            // As `dot`, which warns of "removing empty cluster", a cluster holding no node is not drawn.
            if (!$layers->holds($index)) {
                continue;
            }
            [$left, $top, $right, $bottom] = $position->boxes[$index];
            [$x0, $y0] = $point($left, $top);
            [$x1, $y1] = $point($right, $bottom);
            $clusters[] = new GraphCluster($cluster->id, $cluster->label, \min($x0, $x1), \min($y0, $y1), \max($x0, $x1), \max($y0, $y1), null, null);
        }

        return [$nodes, $endpoints, $clusters];
    }

    /**
     * A map by edge put back in the edges' order.
     *
     * @template T
     * @param array<int, T> $map
     * @param list<int> $order
     * @return array<int, T>
     */
    private static function inOrder(array $map, array $order): array
    {
        $keys = \array_values(\array_intersect($order, \array_keys($map)));

        return \array_combine($keys, \array_map(static fn (int $key) => $map[$key], $keys)) + $map;
    }

    /**
     * Where a layered edge's label stands, as laid out before it is turned: just right of its
     * line, where the line reaches furthest right across its label node's rank, the rank's half
     * height above and below the label's middle, as `dot` is seen to set it, or of the label node;
     * or, on an edge to the next rank, which has no rank between for one, hung by its top right
     * corner from its spline's middle, as `dot` is seen to hang it.
     *
     * @param ?array{float, float} $size the label's width and height as laid out, null for none
     * @param list<array{float, float}> $spline the line drawn for the edge, as laid out, none for none
     * @return ?array{float, float} null for an edge without a label
     */
    private static function label(DotLayers $layers, DotPosition $position, DotSplines $router, int $layered, ?array $size, array $spline): ?array
    {
        $node = $layers->labels[$layered] ?? null;
        if (null !== $node) {
            $rank = $layers->rank[$node];
            $y = $position->y[$rank];
            $half = \max(\array_map(static fn (int $on): float => $layers->height[$on] / 2, $layers->ranks[$rank]));
            // A line that never reaches the rank leaves the label along its label node's side.
            $across = \array_pad(DotSplines::across($spline, $y - $half, $y + $half), length: 1, value: $position->side($node));
            $x = $layers->mirrored ? \min($across) : \max($across);
            // The edges routed after it keep clear of the label as drawn, and, laid out to be turned,
            // the labels routed after it of its line's far side, as `dot` is seen to route them.
            $router->drawn[$node] = $x;
            $router->back[$node] = \max($across);

            return [$layers->mirrored ? $x - ($layers->left[$node] / 2) : $x + ($layers->right[$node] / 2), $y];
        }
        $path = $layers->paths[$layered] ?? null;
        if (null === $path || null === $size) {
            return null;
        }
        [$x, $y] = $router->middle($path);

        return [$x - ($size[0] / 2), $y + ($size[1] / 2)];
    }

    /**
     * A label over the nodes between its edge's ends, and the corners its edge goes through, as
     * `dot` is seen to route it: its sides, a sixth of the way from its top back to the rank's
     * middle; and how far the run between them may rise: no higher than its top.
     *
     * @return array{array{float, float}, array{array{float, float}, array{float, float}}, float}
     */
    private static function bridged(DotLayers $layers, DotPosition $position, int $tail, int $head, float $width, float $height): array
    {
        $centre = self::flatLabel($layers, $position, $tail, $head, $height);
        $top = $centre[1] - ($height / 2);
        $level = $top + (($position->y[$layers->rank[$tail]] - $centre[1]) / 6);

        return [$centre, [[$centre[0] - ($width / 2), $level], [$centre[0] + ($width / 2), $level]], $level - $top];
    }

    /**
     * Where a flat edge's label stands, as `dot` is seen to set it: midway between its ends'
     * facing sides, half its height and 3 more above the edge; over the nodes between its
     * ends, half a rank separation, to the point, above the rank's nodes.
     *
     * @return array{float, float}
     */
    private static function flatLabel(DotLayers $layers, DotPosition $position, int $tail, int $head, float $height): array
    {
        [$left, $right] = $position->x[$tail] <= $position->x[$head] ? [$tail, $head] : [$head, $tail];
        $rank = $layers->rank[$tail];
        $x = ($position->x[$left] + $layers->right[$left] + $position->x[$right] - $layers->left[$right]) / 2;
        if (DotVertical::bridging($layers, $tail, $head)) {
            $half = \max(\array_map(static fn (int $node): float => $layers->height[$node] / 2, $layers->ranks[$rank]));

            return [$x, $position->y[$rank] - $half - \ceil($position->rankSeparation - 1e-9) - ($height / 2)];
        }

        return [$x, $position->y[$rank] - ($height / 2) - 3];
    }

    /**
     * The labels of flat edges between two neighbours, as `dot` is seen to stack them, the widest
     * as drawn first, then the tallest, else in the order written, each as far across as it is
     * tall before any turn: the first 3 above the line, the next 3 below it, each further one 6 beyond
     * the last on its side, every other side in turn; the first edge straight, one below the line
     * round the bottom of its own label, one above round the top of the label before it there.
     *
     * @param list<int> $edgeIndexes the original index of each edge the layers know, in their order
     * @return array<int, array{array{float, float}, ?array{array{float, float}, array{float, float}}}> each label's centre, and the corners its edge goes round, by flat edge
     */
    private static function stacked(DotLayers $layers, DotPosition $position, array $edgeIndexes): array
    {
        $pairs = [];
        foreach ($layers->flat as $index => [$tail, $head, , $labelWidth, $labelHeight]) {
            if (($labelWidth <= 0 && $labelHeight <= 0) || DotVertical::bridging($layers, $tail, $head)) {
                continue;
            }
            $pairs[\min($tail, $head).':'.\max($tail, $head)][] = $index;
        }
        $stacked = [];
        foreach ($pairs as $members) {
            $drawn = static function (int $index) use ($layers, $edgeIndexes): array {
                [, , $layered, $width, $height] = $layers->flat[$index];

                return [...($layers->mirrored ? [$height, $width] : [$width, $height]), -$edgeIndexes[$layered]];
            };
            \usort($members, static fn (int $one, int $other): int => $drawn($other) <=> $drawn($one));
            // The last label on each side: its top, its bottom, its left and its right.
            $sides = [null, null];
            foreach ($members as $k => $index) {
                [$tail, $head, , $width, $height] = $layers->flat[$index];
                $x = self::flatLabel($layers, $position, $tail, $head, $height)[0];
                // Laid out to be turned, as `dot` is seen to, it stands off by its height as drawn: its width here.
                $height = $layers->mirrored ? $width : $height;
                $y = $position->y[$layers->rank[$tail]];
                $last = $sides[$k % 2];
                $box = 0 === ($k % 2)
                    ? [$top = (null === $last ? $y - 3 : $last[0] - 6) - $height, $top + $height, $x - ($width / 2), $x + ($width / 2)]
                    : [$top = null === $last ? $y + 3 : $last[1] + 6, $top + $height, $x - ($width / 2), $x + ($width / 2)];
                $around = match (true) {
                    1 === ($k % 2) => [[$box[2], $box[1]], [$box[3], $box[1]]],
                    null === $last => null,
                    default => [[$last[2], $last[0]], [$last[3], $last[0]]],
                };
                $stacked[$index] = [[$x, ($box[0] + $box[1]) / 2], $around];
                $sides[$k % 2] = $box;
            }
        }

        return $stacked;
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
