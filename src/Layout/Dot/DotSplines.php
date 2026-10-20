<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Edges drawn as splines, as Dobkin et al. route them: each edge between ranks runs through a
 * corridor of boxes — below its tail, the gap between each two ranks, the room beside each of
 * its virtual nodes up to their neighbours and the clusters it is not in, and above its head.
 * The shortest path through the corridor, found by a funnel across the openings between its
 * boxes, is fitted with cubic Bézier curves, each split at the path's corner furthest from it
 * until every piece keeps inside the corridor.
 */
final class DotSplines
{
    /** How many straight steps each curve is drawn in. */
    private const int STEPS = 12;
    /** How far along its tangent each end's control point may stand, as parts of the curve's chord, tried in turn: last, none, a straight line. */
    private const array PULLS = [0.4, 0.25, 0.12, 0.05, 0.0];
    /** How far apart edges sharing a path run, as parts of the node separation: measured on dot. */
    public const float SPREAD = 0.74;
    /** How far from its centre, as parts of the narrower end's half width, an edge of a spread may start. */
    private const float START = 0.8;
    /** How far a curve may stray outside its corridor: rounding. */
    private const float SLACK = 0.01;

    /** @var array<int, float> each rank's half height */
    private array $half = [];
    /** @var array<int, int> each node's place in its rank */
    private array $place = [];

    public function __construct(
        private readonly DotLayers $layers,
        private readonly DotPosition $position,
        private readonly float $nodeSeparation,
    ) {
        foreach ($layers->rank as $node => $rank) {
            $this->half[$rank] = \max($this->half[$rank] ?? 0.0, $layers->height[$node] / 2);
        }
        foreach ($layers->ranks as $nodes) {
            foreach ($nodes as $place => $node) {
                $this->place[$node] = $place;
            }
        }
    }

    /**
     * The spline along a path through the ranks, from its first node's centre to its last's;
     * moved across by an offset, for one of several edges sharing the path, the full offset
     * along its middle and no further than its ends' outlines allow where it starts and ends.
     *
     * @param list<int> $path one edge's nodes, rank by rank, either way
     * @return list<array{float, float}> the curve, drawn in straight steps
     */
    public function route(array $path, float $offset = 0.0): array
    {
        $down = $this->layers->rank[$path[0]] < $this->layers->rank[$path[\count($path) - 1]];
        $path = $down ? $path : \array_reverse($path);
        $boxes = $this->corridor($path);
        $start = $this->centre($path[0]);
        $end = $this->centre($path[\count($path) - 1]);
        $points = self::funnel($start, $end, self::openings($boxes));
        $curves = $this->fit($points, self::unit($points[0], $points[1]), self::unit($points[\count($points) - 2], $points[\count($points) - 1]), $boxes);
        $drawn = [$start];
        foreach ($curves as $curve) {
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $drawn[] = self::at($curve, $step / self::STEPS);
            }
        }

        if (0.0 !== $offset) {
            $drawn = $this->spread($drawn, $offset, $path[0], $path[\count($path) - 1]);
        }

        return $down ? $drawn : \array_reverse($drawn);
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function spread(array $points, float $offset, int $tail, int $head): array
    {
        $layers = $this->layers;
        $narrowest = self::START * \min($layers->left[$tail], $layers->right[$tail], $layers->left[$head], $layers->right[$head]);
        $ends = \max(-$narrowest, \min($narrowest, $offset));
        $lengths = [0.0];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $lengths[] = $lengths[$index - 1] + \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }
        $total = \max($lengths[\count($lengths) - 1], 1e-9);
        foreach ($points as $index => [$x, $y]) {
            // The offset swells from what the ends allow to its full width midway.
            $swell = \sin((\M_PI * $lengths[$index]) / $total);
            $points[$index] = [$x + $ends + (($offset - $ends) * $swell), $y];
        }

        return $points;
    }

    /**
     * @param list<int> $path top to bottom
     * @return list<array{float, float, float, float}> left, top, right, bottom
     */
    private function corridor(array $path): array
    {
        $layers = $this->layers;
        $x = $this->position->x;
        $y = $this->position->y;
        $tail = $path[0];
        $open = $this->open($layers->cluster[$tail]) + $this->open($layers->cluster[$path[\count($path) - 1]]);
        $rank = $layers->rank[$tail];
        $boxes = [[$x[$tail] - $layers->left[$tail], $y[$rank], $x[$tail] + $layers->right[$tail], $y[$rank] + $this->half[$rank]]];
        $last = \count($path) - 1;
        for ($index = 1; $index <= $last; ++$index) {
            $node = $path[$index];
            $rank = $layers->rank[$node];
            [$left, $right] = $index === $last ? [$x[$node] - $layers->left[$node], $x[$node] + $layers->right[$node]] : $this->beside($node);
            $box = [$left, $y[$rank] - $this->half[$rank], $right, $index === $last ? $y[$rank] : $y[$rank] + $this->half[$rank]];
            \array_push($boxes, ...$this->gap($boxes[\count($boxes) - 1], $box, $open));
            $boxes[] = $box;
        }

        return $boxes;
    }

    /**
     * The clusters an edge may pass through: those holding either of its ends.
     *
     * @return array<int, true>
     */
    private function open(int $cluster): array
    {
        $open = [];
        for (; -1 !== $cluster; $cluster = $this->layers->parents[$cluster]) {
            $open[$cluster] = true;
        }

        return $open;
    }

    /**
     * The gap between two ranks, from the box above to the box below: as wide as both, but
     * clear of the clusters the edge may not pass through where they reach into it. Cut into
     * slabs where such a cluster starts or ends, each slab keeps the free stretch that meets
     * the one above it, and the box below where it can.
     *
     * @param array{float, float, float, float} $above
     * @param array{float, float, float, float} $below
     * @param array<int, true> $open
     * @return list<array{float, float, float, float}>
     */
    private function gap(array $above, array $below, array $open): array
    {
        [$left, $right] = [\min($above[0], $below[0]), \max($above[2], $below[2])];
        [$top, $bottom] = [$above[3], $below[1]];
        $blocks = [];
        $cuts = [$top, $bottom];
        foreach ($this->position->boxes as $cluster => [$x0, $y0, $x1, $y1]) {
            if (\array_key_exists($cluster, $open) || $x1 <= $left || $x0 >= $right || $y1 <= $top || $y0 >= $bottom) {
                continue;
            }
            $blocks[] = [$x0, \max($y0, $top), $x1, \min($y1, $bottom)];
            \array_push($cuts, \max($y0, $top), \min($y1, $bottom));
        }
        $cuts = \array_values(\array_unique($cuts, \SORT_REGULAR));
        \sort($cuts);
        $boxes = [];
        $previous = [$above[0], $above[2]];
        for ($index = 1, $count = \count($cuts); $index < $count; ++$index) {
            [$from, $to] = [$cuts[$index - 1], $cuts[$index]];
            $free = [[$left, $right]];
            foreach ($blocks as [$x0, $y0, $x1, $y1]) {
                if ($y0 >= $to || $y1 <= $from) {
                    continue;
                }
                $free = self::without($free, $x0, $x1);
            }
            $previous = self::meeting($free, $previous, [$below[0], $below[2]]);
            $boxes[] = [$previous[0], $from, $previous[1], $to];
        }

        return $boxes;
    }

    /**
     * Stretches across with one taken out.
     *
     * @param list<array{float, float}> $stretches
     * @return list<array{float, float}>
     */
    private static function without(array $stretches, float $from, float $to): array
    {
        $kept = [];
        foreach ($stretches as [$start, $end]) {
            if ($start < $from) {
                $kept[] = [$start, \min($end, $from)];
            }
            if ($end > $to) {
                $kept[] = [\max($start, $to), $end];
            }
        }

        return $kept;
    }

    /**
     * Of the free stretches, the one meeting the stretch above most, then the one below.
     *
     * @param list<array{float, float}> $free
     * @param array{float, float} $above
     * @param array{float, float} $below
     * @return array{float, float}
     */
    private static function meeting(array $free, array $above, array $below): array
    {
        $best = $above;
        $score = -\INF;
        foreach ($free as $stretch) {
            $meets = \min($stretch[1], $above[1]) - \max($stretch[0], $above[0]);
            $reaches = \min($stretch[1], $below[1]) - \max($stretch[0], $below[0]);
            $value = ($meets > 0 ? 1e6 : 0.0) + ($reaches > 0 ? 1e3 : 0.0) + $meets;
            if ($value > $score) {
                [$best, $score] = [$stretch, $value];
            }
        }

        return $best;
    }

    /**
     * How far across the room beside a virtual node reaches: halfway to its neighbours, and
     * no further than the clusters they lie in and it does not. A label node keeps the edge
     * to the left of its label.
     *
     * @return array{float, float} left and right
     */
    private function beside(int $node): array
    {
        $layers = $this->layers;
        $rank = $layers->ranks[$layers->rank[$node]];
        $place = $this->place[$node];
        $left = 0.0;
        $right = $this->position->width;
        $previous = $rank[$place - 1] ?? null;
        if (null !== $previous) {
            $left = \max($this->position->x[$previous] + $layers->right[$previous] + ($this->nodeSeparation / 2), $this->apart($previous, $node, side: 1));
        }
        $next = $rank[$place + 1] ?? null;
        if (null !== $next) {
            $right = \min($this->position->x[$next] - $layers->left[$next] - ($this->nodeSeparation / 2), $this->apart($next, $node, side: 0));
        }
        if (DotLayers::LABEL === $layers->kind[$node]) {
            $right = $this->position->x[$node] + $layers->left[$node];
        }

        return [\min($left, $this->position->x[$node]), \max($right, $this->position->x[$node])];
    }

    /**
     * The side facing the node, its left side, 0, or its right, 1, of the outermost cluster
     * that holds the neighbour and not the node; none where they share every cluster.
     */
    private function apart(int $neighbour, int $node, int $side): float
    {
        $shared = $this->layers->common($this->layers->cluster[$neighbour], $this->layers->cluster[$node]);
        $edge = 1 === $side ? -\INF : \INF;
        for ($cluster = $this->layers->cluster[$neighbour]; -1 !== $cluster && $cluster !== $shared; $cluster = $this->layers->parents[$cluster]) {
            $edge = $this->position->boxes[$cluster][1 === $side ? 2 : 0];
        }

        return $edge;
    }

    /** @return array{float, float} */
    private function centre(int $node): array
    {
        return [$this->position->x[$node], $this->position->y[$this->layers->rank[$node]]];
    }

    /**
     * The openings between each box and the next: where they meet, as far across as both reach.
     *
     * @param list<array{float, float, float, float}> $boxes
     * @return list<array{array{float, float}, array{float, float}}> each opening's left and right end
     */
    private static function openings(array $boxes): array
    {
        $openings = [];
        for ($index = 1, $count = \count($boxes); $index < $count; ++$index) {
            [$above, $below] = [$boxes[$index - 1], $boxes[$index]];
            $left = \max($above[0], $below[0]);
            $right = \max($left, \min($above[2], $below[2]));
            $openings[] = [[$left, $below[1]], [$right, $below[1]]];
        }

        return $openings;
    }

    /**
     * The shortest path down through the openings: a funnel from the start narrows opening by
     * opening; where one side would cross the other, the path turns at that side's end.
     *
     * @param array{float, float} $start
     * @param array{float, float} $end
     * @param list<array{array{float, float}, array{float, float}}> $openings
     * @return list<array{float, float}>
     */
    private static function funnel(array $start, array $end, array $openings): array
    {
        $openings = [[$start, $start], ...$openings, [$end, $end]];
        $path = [$start];
        $apex = $start;
        [$left, $right] = [$start, $start];
        [$apexAt, $leftAt, $rightAt] = [0, 0, 0];
        $count = \count($openings);
        for ($index = 1; $index < $count; ++$index) {
            [$nextLeft, $nextRight] = $openings[$index];
            // A side narrows the funnel unless it would cross the other, where the path turns at the other's end.
            if (self::turn($apex, $right, $nextRight) >= 0) {
                if ($apex !== $right && self::turn($apex, $left, $nextRight) >= 0) {
                    $path[] = $left;
                    [$apex, $apexAt] = [$left, $leftAt];
                    [$left, $right, $leftAt, $rightAt] = [$apex, $apex, $apexAt, $apexAt];
                    $index = $apexAt;
                    continue;
                }
                [$right, $rightAt] = [$nextRight, $index];
            }
            if (self::turn($apex, $left, $nextLeft) <= 0) {
                if ($apex !== $left && self::turn($apex, $right, $nextLeft) <= 0) {
                    $path[] = $right;
                    [$apex, $apexAt] = [$right, $rightAt];
                    [$left, $right, $leftAt, $rightAt] = [$apex, $apex, $apexAt, $apexAt];
                    $index = $apexAt;
                    continue;
                }
                [$left, $leftAt] = [$nextLeft, $index];
            }
        }
        if ($path[\count($path) - 1] !== $end) {
            $path[] = $end;
        }

        return $path;
    }

    /**
     * Which way a path from `$from` through `$to` turns to reach `$point`: positive one way,
     * negative the other, zero straight on.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @param array{float, float} $point
     */
    private static function turn(array $from, array $to, array $point): float
    {
        return (($to[0] - $from[0]) * ($point[1] - $from[1])) - (($to[1] - $from[1]) * ($point[0] - $from[0]));
    }

    /**
     * Cubic Béziers along a path, leaving and reaching it along the tangents given, each
     * inside the corridor: one if it fits, else two split at the corner furthest from the chord.
     *
     * @param list<array{float, float}> $points
     * @param array{float, float} $leaving
     * @param array{float, float} $reaching
     * @param list<array{float, float, float, float}> $boxes
     * @return list<array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}>
     */
    private function fit(array $points, array $leaving, array $reaching, array $boxes): array
    {
        $first = $points[0];
        $last = $points[\count($points) - 1];
        $chord = \hypot($last[0] - $first[0], $last[1] - $first[1]);
        foreach (self::PULLS as $pull) {
            $reach = $chord * $pull;
            $curve = [$first, [$first[0] + ($leaving[0] * $reach), $first[1] + ($leaving[1] * $reach)], [$last[0] - ($reaching[0] * $reach), $last[1] - ($reaching[1] * $reach)], $last];
            // A straight stretch of the shortest path lies inside, but for rounding.
            if (self::inside($curve, $boxes) || (0.0 === $pull && \count($points) < 3)) {
                return [$curve];
            }
        }
        $split = self::furthest($points);
        $tangent = self::unit($points[$split - 1], $points[$split + 1]);

        return [
            ...$this->fit(\array_slice($points, offset: 0, length: $split + 1), $leaving, $tangent, $boxes),
            ...$this->fit(\array_slice($points, $split), $tangent, $reaching, $boxes),
        ];
    }

    /**
     * The inner corner furthest from the chord between the path's ends.
     *
     * @param list<array{float, float}> $points
     */
    private static function furthest(array $points): int
    {
        $first = $points[0];
        $last = $points[\count($points) - 1];
        $best = 1;
        $distance = -1.0;
        for ($index = 1, $count = \count($points) - 1; $index < $count; ++$index) {
            $away = \abs(self::turn($first, $last, $points[$index]));
            if ($away > $distance) {
                [$best, $distance] = [$index, $away];
            }
        }

        return $best;
    }

    /**
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @param list<array{float, float, float, float}> $boxes
     */
    private static function inside(array $curve, array $boxes): bool
    {
        for ($step = 1; $step < (2 * self::STEPS); ++$step) {
            [$x, $y] = self::at($curve, $step / (2 * self::STEPS));
            $within = false;
            foreach ($boxes as [$left, $top, $right, $bottom]) {
                if ($x < ($left - self::SLACK) || $x > ($right + self::SLACK) || $y < ($top - self::SLACK) || $y > ($bottom + self::SLACK)) {
                    continue;
                }
                $within = true;
                break;
            }
            if (!$within) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @return array{float, float}
     */
    private static function at(array $curve, float $t): array
    {
        $u = 1 - $t;
        [$a, $b, $c, $d] = $curve;

        return [
            (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
            (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
        ];
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function unit(array $from, array $to): array
    {
        $length = \hypot($to[0] - $from[0], $to[1] - $from[1]);

        return $length > 0 ? [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length] : [0.0, 1.0];
    }
}
