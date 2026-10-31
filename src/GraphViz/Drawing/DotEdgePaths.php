<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Route;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Layout\Curve\CurvePath;
use Janalis\Viz\Layout\Curve\Polyline;
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
    /** How far out of its node an end whose port names a compass point leaves, and runs round it where it must. */
    private const float OUT = 10.0;
    /** The way out of a node from each compass point. */
    private const array OUTWARD = [
        'n' => [0.0, -1.0],
        'ne' => [\M_SQRT1_2, -\M_SQRT1_2],
        'e' => [1.0, 0.0],
        'se' => [\M_SQRT1_2, \M_SQRT1_2],
        's' => [0.0, 1.0],
        'sw' => [-\M_SQRT1_2, \M_SQRT1_2],
        'w' => [-1.0, 0.0],
        'nw' => [-\M_SQRT1_2, -\M_SQRT1_2],
    ];

    /** @var array<string, ?DotRecord> each record node's fields, read once */
    private array $records = [];
    /** Square paths, routed together the first time one is asked for. */
    private ?DotOrtho $ortho = null;

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
        // A loop is drawn on its node's right, or out of the side its ports name; an edge between ranks has its spline, bent to any port.
        $loop = $edge->tail === $edge->head;
        [$leaving, $reaching] = [$this->outward($edge->tail, $edge->attribute('tailport')), $this->outward($edge->head, $edge->attribute('headport'))];
        $points = match (true) {
            ($loop || null === $route) && 'ortho' === $splines => self::squareLoop($tail),
            $loop && null !== $leaving && null !== $reaching => self::sideLoop($leaving, $reaching),
            $loop || null === $route => self::loop($tail, ...$this->loopReach($index)),
            'ortho' === $splines => $this->ortho()->path($index),
            \in_array($splines, ['line', 'false'], strict: true) => $this->ported([$route[0], $route[\count($route) - 1]], $edge),
            'polyline' === $splines && null !== $polyline => $this->bent($polyline, $edge),
            null !== $spline => $this->bent($spline, $edge),
            default => Route::smooth($this->ported($route, $edge)),
        };
        if (!$loop && 'ortho' !== $splines) {
            $points = match (true) {
                null !== $leaving && null !== $reaching => self::between($tail, $leaving, $head, $reaching),
                null !== $leaving => self::leaving($points, $tail, $leaving),
                null !== $reaching => \array_reverse(self::leaving(\array_reverse($points), $head, $reaching)),
                default => $points,
            };
        }

        return $this->clip($points, $edge, $tail, $head);
    }

    /**
     * The compass point an end's port names, and the way out of its node from it, as `dot`
     * routes such an end; null for a port naming none, or the centre.
     *
     * @return ?array{array{float, float}, array{float, float}}
     */
    private function outward(string $name, string $port): ?array
    {
        $record = $this->record($name);
        [$point, , $compass] = DotPorts::anchor($this->graph, $this->graph->nodes[$name], $record, $port, self::box($this->placement->endpoints[$name]));
        $way = self::OUTWARD[$compass] ?? null;

        return null === $point || null === $way ? null : [$point, $way];
    }

    /**
     * A path made to leave its start's compass point the way out of the node there, as `dot`
     * is seen to route one: out a little, then round the node's corners, the shorter way, until
     * where the path leaves the node is in sight, and on along it.
     *
     * @param list<array{float, float}> $points from the compass point on
     * @param array{array{float, float}, array{float, float}} $out the point and the way out from it
     * @return list<array{float, float}>
     */
    private static function leaving(array $points, GraphNode $node, array $out): array
    {
        $box = self::box($node);
        $index = 1;
        $count = \count($points);
        while ($index < ($count - 1) && self::inBox($points[$index], $box)) {
            ++$index;
        }
        $exit = $points[$index];
        [$start, $via] = self::rounding($box, $out, $exit);

        return [...Route::smooth([$out[0], $start, ...$via, $exit]), ...\array_slice($points, $index + 1)];
    }

    /**
     * A path between two compass points, each end out of its node the way out from it and
     * round it until the other's way out is in sight.
     *
     * @param array{array{float, float}, array{float, float}} $leaving
     * @param array{array{float, float}, array{float, float}} $reaching
     * @return list<array{float, float}>
     */
    private static function between(GraphNode $tail, array $leaving, GraphNode $head, array $reaching): array
    {
        $headBox = self::box($head);
        [$end] = self::rounding($headBox, $reaching, $leaving[0]);
        [$start, $out] = self::rounding(self::box($tail), $leaving, $end);
        [, $in] = self::rounding($headBox, $reaching, $out[\count($out) - 1] ?? $start);

        return Route::smooth([$leaving[0], $start, ...$out, ...\array_reverse($in), $end, $reaching[0]]);
    }

    /**
     * Where an end leaves its node's box, a little out from the side its way out points to, and
     * the corners round the box from there, the shorter way, until a target is in sight.
     *
     * @param array{float, float, float, float} $box
     * @param array{array{float, float}, array{float, float}} $out the point and the way out from it
     * @param array{float, float} $target
     * @return array{array{float, float}, list<array{float, float}>}
     */
    private static function rounding(array $box, array $out, array $target): array
    {
        [[$x, $y], $way] = $out;
        [$left, $top, $right, $bottom] = $box;
        $start = [self::beyond($way[0], $left, $right, $x), self::beyond($way[1], $top, $bottom, $y)];
        // The corners a little out from the box, clockwise from the top right, and the side the end leaves by.
        $corners = [[$right + self::OUT, $top - self::OUT], [$right + self::OUT, $bottom + self::OUT], [$left - self::OUT, $bottom + self::OUT], [$left - self::OUT, $top - self::OUT]];
        $from = match (true) {
            \abs($way[0]) > \abs($way[1]) => $way[0] > 0 ? 1 : 3,
            default => $way[1] > 0 ? 2 : 0,
        };
        $ways = [];
        foreach ([0, 3] as $turned) {
            $via = [];
            $at = $start;
            for ($turn = 0; $turn < 4 && self::crosses($at, $target, $box); ++$turn) {
                $at = $corners[($from + (0 === $turned ? $turn : $turned - $turn)) % 4];
                $via[] = $at;
            }
            $ways[] = $via;
        }

        return [$start, Polyline::length([$start, ...$ways[0], $target]) <= Polyline::length([$start, ...$ways[1], $target]) ? $ways[0] : $ways[1]];
    }

    /** Along one axis, a little out from the box's side the way out points to, or where the end is. */
    private static function beyond(float $way, float $low, float $high, float $at): float
    {
        return match (true) {
            $way < 0 => $low - self::OUT,
            $way > 0 => $high + self::OUT,
            default => $at,
        };
    }

    /**
     * Whether a point stands inside a box.
     *
     * @param array{float, float} $point
     * @param array{float, float, float, float} $box
     */
    private static function inBox(array $point, array $box): bool
    {
        return $point[0] > $box[0] && $point[0] < $box[2] && $point[1] > $box[1] && $point[1] < $box[3];
    }

    /**
     * Whether a segment runs through a box's inside, clipped against each side in turn.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @param array{float, float, float, float} $box
     */
    private static function crosses(array $from, array $to, array $box): bool
    {
        [$dx, $dy] = [$to[0] - $from[0], $to[1] - $from[1]];
        [$enter, $leave] = [0.0, 1.0];
        foreach ([[-$dx, $from[0] - $box[0]], [$dx, $box[2] - $from[0]], [-$dy, $from[1] - $box[1]], [$dy, $box[3] - $from[1]]] as [$p, $q]) {
            if (0.0 === $p) {
                if ($q <= 0) {
                    return false;
                }
                continue;
            }
            $t = $q / $p;
            [$enter, $leave] = $p < 0 ? [\max($enter, $t), $leave] : [$enter, \min($leave, $t)];
        }

        return $enter < $leave;
    }

    /**
     * A loop between two compass points on a node, as `dot` is seen to draw one: out of the
     * first the way out from it, round, and back into the second.
     *
     * @param array{array{float, float}, array{float, float}} $tail
     * @param array{array{float, float}, array{float, float}} $head
     * @return list<array{float, float}>
     */
    private static function sideLoop(array $tail, array $head): array
    {
        [[$from, $out], [$to, $in]] = [$tail, $head];
        $reach = 2 * self::OUT;
        // Across the way out, the loop bulging either side of its ends.
        [$acrossX, $acrossY] = [-$out[1] * self::OUT, $out[0] * self::OUT];

        return Route::smooth([
            $from,
            [$from[0] + ($out[0] * $reach) - $acrossX, $from[1] + ($out[1] * $reach) - $acrossY],
            [$to[0] + ($in[0] * $reach) + $acrossX, $to[1] + ($in[1] * $reach) + $acrossY],
            $to,
        ]);
    }

    private function ortho(): DotOrtho
    {
        return $this->ortho ??= new DotOrtho($this->graph, $this->placement);
    }

    /**
     * The parts of its path an edge draws, each clipped where it reaches the edge's ends, and
     * whether it starts at the tail and ends at the head: the whole path, or, where edges merge,
     * the stretches no edge before drew; none for an edge they drew wholly.
     *
     * @return list<array{list<array{float, float}>, bool, bool}>
     */
    public function pieces(int $index, DotEdge $edge, string $splines): array
    {
        $parts = $this->placement->pieces[$index] ?? null;
        if (null === $parts) {
            return [[$this->path($index, $edge, $splines), true, true]];
        }
        $tail = $this->placement->endpoints[$edge->tail];
        $head = $this->placement->endpoints[$edge->head];

        return \array_map(fn (array $part): array => [
            $this->clip(
                match (true) {
                    \in_array($splines, ['line', 'false'], strict: true) => [$part[1][0], $part[1][\count($part[1]) - 1]],
                    \in_array($splines, ['polyline', 'ortho'], strict: true) => $part[1],
                    default => $part[0],
                },
                $edge,
                $tail,
                $head,
            ),
            $part[2],
            $part[3],
        ], $parts);
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

    /** @return list<array{float, float}> a square loop out to a node's right and back, for `splines=ortho` */
    private static function squareLoop(GraphNode $node): array
    {
        $out = $node->x + ($node->w / 2) + \max(DotLayers::LOOP, $node->h / 2);

        return [[$node->x, $node->y - ($node->h / 4)], [$out, $node->y - ($node->h / 4)], [$out, $node->y + ($node->h / 4)], [$node->x, $node->y + ($node->h / 4)]];
    }

    /**
     * How far right of its node's centre a loop reaches, and how far above and below it bulges,
     * as `dot` is seen to draw the loops on a node: each 18 beyond the one before and its label,
     * the i-th of n bulging i/n of a quarter of the node's height.
     *
     * @return array{float, float}
     */
    public function loopReach(int $index): array
    {
        $edge = $this->graph->edges[$index];
        $node = $this->placement->endpoints[$edge->tail];
        [$before, $at, $count] = [0.0, 0, 0];
        foreach ($this->graph->edges as $other => $loop) {
            if ($loop->tail !== $edge->tail || $loop->head !== $edge->tail) {
                continue;
            }
            ++$count;
            if ($other < $index) {
                ++$at;
                $before += DotNodes::edgeText($this->graph, $loop)->size()[0];
            }
        }

        return [($node->w / 2) + (DotLayers::LOOP * ($at + 1)) + $before, (($node->h / 4) * ($at + 1)) / \max(1, $count)];
    }

    /**
     * A loop out to a node's right and back, as `dot` is seen to draw one: from the node's
     * centre, a curve out to its reach, held a third of the way out and at the reach as far up
     * as it bulges, then back as far down — clipped at the node's outline afterwards.
     *
     * @return list<array{float, float}>
     */
    private static function loop(GraphNode $node, float $reach, float $bulge): array
    {
        [$x, $y] = [$node->x, $node->y];
        [$out, $third] = [$x + $reach, $x + ($reach / 3)];
        $path = new CurvePath([$x, $y]);
        $path->cubic([$third, $y - $bulge], [$out, $y - $bulge], [$out, $y]);
        $path->cubic([$out, $y + $bulge], [$third, $y + $bulge], [$x, $y]);

        return $path->points;
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
