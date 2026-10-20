<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Route;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Layout\Dot\DotLayers;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;

/**
 * Where a laid-out DOT graph's edges run: each along its spline, its corners or a straight
 * line, as `splines` asks, bent to its ports, and clipped where it leaves its tail's outline,
 * or its port's field or its `ltail` cluster, and meets its head's; a loop on its node's right.
 */
final class DotEdgePaths
{
    /** @var array<string, ?DotRecord> each record node's fields, read once */
    private array $records = [];

    /** @param array<string, DotShape> $shapes each node's shape, by name */
    public function __construct(
        private readonly DotGraph $graph,
        private readonly Placement $placement,
        private readonly array $shapes,
    ) {}

    /** @return array{float, float, float, float} a node's box: left, top, right, bottom */
    public static function box(GraphNode $node): array
    {
        return [$node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2)];
    }

    /**
     * An edge's path, clipped at its ends' outlines: a loop's on its node's right; else its
     * spline, its corners, or a straight line, as `splines` asks, bent to any port.
     *
     * @return list<array{float, float}>
     */
    public function path(int $index, DotEdge $edge, string $splines): array
    {
        $tail = $this->placement->endpoints[$edge->tail];
        $head = $this->placement->endpoints[$edge->head];
        $route = $this->placement->routes[$index] ?? null;
        $spline = $this->placement->splines[$index] ?? null;
        $polyline = $this->placement->polylines[$index] ?? $route;
        // Only a loop has no route; an edge between ranks has its spline, bent to any port.
        $points = match (true) {
            null === $route => self::loop($tail),
            \in_array($splines, ['line', 'false'], strict: true) => $this->ported([$route[0], $route[\count($route) - 1]], $edge),
            'polyline' === $splines && null !== $polyline => $this->bent($polyline, $edge),
            null !== $spline => $this->bent($spline, $edge),
            default => Route::smooth($this->ported($route, $edge)),
        };

        return $this->clip($points, $edge, $tail, $head);
    }

    /**
     * Where edges sharing a `samehead` at their head, or a `sametail` at their tail, meet: on
     * the node's outline, toward where their ends would fall on their own, on average.
     *
     * @param array<int, DotEdge> $edges
     * @return array<int, array{?array{float, float}, ?array{float, float}}> where each such edge's tail and head meet the others', by edge index
     */
    public function meet(array $edges, string $splines): array
    {
        $groups = [];
        $meets = [];
        foreach ($edges as $index => $edge) {
            foreach ([['sametail', $edge->tail, 0], ['samehead', $edge->head, 1]] as [$attribute, $node, $end]) {
                $value = $edge->attribute($attribute);
                if ('' === $value) {
                    continue;
                }
                $groups[$attribute."\0".$node."\0".$value] ??= [$node, []];
                $groups[$attribute."\0".$node."\0".$value][1][] = [$index, $end];
            }
        }
        foreach ($groups as [$name, $members]) {
            $ends = [];
            foreach ($members as [$index, $end]) {
                $points = $this->path($index, $edges[$index], $splines);
                $ends[] = 0 === $end ? $points[0] : $points[\count($points) - 1];
            }
            $node = $this->placement->endpoints[$name];
            [$x, $y] = [\array_sum(\array_column($ends, 0)) / \count($ends), \array_sum(\array_column($ends, 1)) / \count($ends)];
            $length = \hypot($x - $node->x, $y - $node->y);
            $reach = $node->w + $node->h;
            // From the centre out through their mean, to the outline.
            $far = $length > 0 ? [$node->x + ((($x - $node->x) * $reach) / $length), $node->y + ((($y - $node->y) * $reach) / $length)] : [$node->x, $node->y + $reach];
            [$point] = $this->cut([[$node->x, $node->y], $far], $name, $node, null);
            foreach ($members as [$index, $end]) {
                $meets[$index] ??= [null, null];
                $meets[$index][$end] = $point;
            }
        }

        return $meets;
    }

    /** @param array{float, float} $point */
    private function inside(string $name, GraphNode $node, array $point): bool
    {
        if (\in_array($this->graph->nodes[$name]->attribute('shape', 'ellipse'), DotShape::BARE, strict: true)) {
            return \abs($point[0] - $node->x) < ($node->w / 2) && \abs($point[1] - $node->y) < ($node->h / 2);
        }

        return $this->shapes[$name]->contains($point[0] - $node->x, $point[1] - $node->y);
    }

    /** @return list<array{float, float}> a loop out to a node's right and back */
    private static function loop(GraphNode $node): array
    {
        $right = $node->x + ($node->w / 2);
        $reach = \max(DotLayers::LOOP, $node->h / 2);

        return Route::smooth([
            [$node->x, $node->y - ($node->h / 4)],
            [$right + ($reach / 2), $node->y - ($node->h / 2)],
            [$right + $reach, $node->y],
            [$right + ($reach / 2), $node->y + ($node->h / 2)],
            [$node->x, $node->y + ($node->h / 4)],
        ]);
    }

    /**
     * A spline bent to start and end at its edge's ports: each end moved to its port, the move
     * easing out along the curve, so it still leaves and reaches its ends the way it did.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function bent(array $points, DotEdge $edge): array
    {
        $tail = $this->anchor($edge->tail, $edge->attribute('tailport'))[0] ?? $points[0];
        $head = $this->anchor($edge->head, $edge->attribute('headport'))[0] ?? $points[\count($points) - 1];

        return Route::eased($points, $tail, $head);
    }

    /**
     * A path moved to start and end at its edge's ports: a record's field, at its centre, or
     * a compass point on the side of the node, or of the field, it names.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function ported(array $points, DotEdge $edge): array
    {
        $tail = $this->anchor($edge->tail, $edge->attribute('tailport'));
        $head = $this->anchor($edge->head, $edge->attribute('headport'));
        $last = \count($points) - 1;
        $points[0] = $tail[0] ?? $points[0];
        $points[$last] = $head[0] ?? $points[$last];

        return $points;
    }

    /**
     * Where a port puts an edge's end, and the box it is clipped at: a record field's, which
     * draws no fill to hide it. An HTML cell's port aims the end at the cell and clips it at the
     * node's outline, so no cell drawn over the edge hides its arrowhead.
     *
     * @return array{?array{float, float}, ?array{float, float, float, float}}
     */
    private function anchor(string $name, string $port): array
    {
        $record = $this->record($name);
        [$point, $field] = DotPorts::anchor($this->graph, $this->graph->nodes[$name], $record, $port, self::box($this->placement->endpoints[$name]));

        return [$point, null === $record ? null : $field];
    }

    /**
     * Trims a path to where it leaves its tail's outline, or its port's field, and meets its head's.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function clip(array $points, DotEdge $edge, GraphNode $tail, GraphNode $head): array
    {
        if ('false' !== \strtolower($edge->attribute('tailclip'))) {
            $points = $this->cut($points, $edge->tail, $tail, $this->compound($edge->attribute('ltail'), $head) ?? $this->anchor($edge->tail, $edge->attribute('tailport'))[1]);
        }
        if ('false' !== \strtolower($edge->attribute('headclip'))) {
            $points = \array_reverse($this->cut(
                \array_reverse($points),
                $edge->head,
                $head,
                $this->compound($edge->attribute('lhead'), $tail) ?? $this->anchor($edge->head, $edge->attribute('headport'))[1],
            ));
        }

        return \count($points) >= 2 ? $points : [$points[0] ?? [$tail->x, $tail->y], $points[0] ?? [$head->x, $head->y]];
    }

    /**
     * The box of the cluster an edge's `lhead` or `ltail` names, which it is clipped at, as dot
     * does in a `compound` graph; null when the graph is not compound, no cluster has the name,
     * or the edge's other end stands inside it.
     *
     * @return ?array{float, float, float, float}
     */
    private function compound(string $cluster, GraphNode $other): ?array
    {
        if ('true' !== \strtolower($this->graph->attribute('compound')) || '' === $cluster) {
            return null;
        }
        foreach ($this->placement->graph->clusters as $box) {
            if ($box->name !== $cluster) {
                continue;
            }
            $inside = $other->x > $box->x0 && $other->x < $box->x1 && $other->y > $box->y0 && $other->y < $box->y1;

            return $inside ? null : [$box->x0, $box->y0, $box->x1, $box->y1];
        }

        return null;
    }

    /**
     * Whether a point falls inside where an edge's end is clipped: its port's field, or its node's outline.
     *
     * @param ?array{float, float, float, float} $field
     * @param array{float, float} $point
     */
    private function within(string $name, GraphNode $node, ?array $field, array $point): bool
    {
        if (null === $field) {
            return $this->inside($name, $node, $point);
        }
        [$left, $top, $right, $bottom] = $field;

        return $point[0] > $left && $point[0] < $right && $point[1] > $top && $point[1] < $bottom;
    }

    /**
     * Drops the points at the start of a path that fall inside a node, or its port's field, the crossing found by bisection.
     *
     * @param list<array{float, float}> $points
     * @param ?array{float, float, float, float} $field the port's field the end is clipped at, if any
     * @return list<array{float, float}>
     */
    private function cut(array $points, string $name, GraphNode $node, ?array $field): array
    {
        $count = \count($points);
        $index = 0;
        while ($index < $count && $this->within($name, $node, $field, $points[$index])) {
            ++$index;
        }
        if (0 === $index || $count === $index) {
            return $points;
        }
        [$in, $out] = [$points[$index - 1], $points[$index]];
        for ($step = 0; $step < 12; ++$step) {
            $middle = [($in[0] + $out[0]) / 2, ($in[1] + $out[1]) / 2];
            [$in, $out] = $this->within($name, $node, $field, $middle) ? [$middle, $out] : [$in, $middle];
        }

        return [$out, ...\array_slice($points, $index)];
    }

    /** A record node's fields, read once however many edges reach it; null for any other node. */
    public function record(string $name): ?DotRecord
    {
        if (!\array_key_exists($name, $this->records)) {
            $this->records[$name] = DotNodes::record($this->graph, $this->graph->nodes[$name]);
        }

        return $this->records[$name];
    }
}
