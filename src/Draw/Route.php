<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** An edge's route through its waypoints, as a drawing draws it: smoothed into a curve, and shortened for the mark at its end. */
final class Route
{
    /**
     * A route smoothed into a curve through its points, as a polyline.
     *
     * @param list<array{float, float}> $route
     * @return list<array{float, float}>
     */
    public static function smooth(array $route): array
    {
        if (\count($route) < 3) {
            return $route;
        }
        $points = [$route[0]];
        $count = \count($route);
        for ($index = 0; $index < ($count - 1); ++$index) {
            $p0 = $route[\max(0, $index - 1)];
            [$p1, $p2] = [$route[$index], $route[$index + 1]];
            $p3 = $route[\min($count - 1, $index + 2)];
            // A Catmull-Rom segment between p1 and p2, as a cubic Bézier, flattened.
            $c1 = [$p1[0] + (($p2[0] - $p0[0]) / 6), $p1[1] + (($p2[1] - $p0[1]) / 6)];
            $c2 = [$p2[0] - (($p3[0] - $p1[0]) / 6), $p2[1] - (($p3[1] - $p1[1]) / 6)];
            for ($step = 1; $step <= 8; ++$step) {
                $t = $step / 8;
                $u = 1 - $t;
                $points[] = [
                    (($u ** 3) * $p1[0]) + (3 * $u * $u * $t * $c1[0]) + (3 * $u * $t * $t * $c2[0]) + (($t ** 3) * $p2[0]),
                    (($u ** 3) * $p1[1]) + (3 * $u * $u * $t * $c1[1]) + (3 * $u * $t * $t * $c2[1]) + (($t ** 3) * $p2[1]),
                ];
            }
        }

        return $points;
    }

    /**
     * Shortens a path's end by an arrowhead's length: the path, the arrow's tip, and its unit direction.
     *
     * @param list<array{float, float}> $points
     * @return array{list<array{float, float}>, array{float, float}, array{float, float}}
     */
    public static function shorten(array $points, float $length): array
    {
        $tip = $points[\count($points) - 1];
        $remaining = $length;
        $kept = $points;
        while (\count($kept) >= 2) {
            $end = $kept[\count($kept) - 1];
            $before = $kept[\count($kept) - 2];
            $segment = \hypot($end[0] - $before[0], $end[1] - $before[1]);
            if ($segment >= $remaining) {
                $share = $segment > 0 ? $remaining / $segment : 0.0;
                $kept[\count($kept) - 1] = [$end[0] + (($before[0] - $end[0]) * $share), $end[1] + (($before[1] - $end[1]) * $share)];
                break;
            }
            $remaining -= $segment;
            \array_pop($kept);
        }
        $base = $kept[\count($kept) - 1];
        $distance = \hypot($tip[0] - $base[0], $tip[1] - $base[1]);
        $along = $distance > 0 ? [($tip[0] - $base[0]) / $distance, ($tip[1] - $base[1]) / $distance] : [0.0, 1.0];

        return [\count($kept) >= 2 ? $kept : [$base, $base], $tip, $along];
    }

    /**
     * Where a path ends, and the unit direction it arrives in from its last distinct point.
     *
     * @param list<array{float, float}> $points
     * @return array{array{float, float}, array{float, float}}
     */
    public static function end(array $points): array
    {
        $tip = $points[\count($points) - 1];
        for ($index = \count($points) - 2; $index >= 0; --$index) {
            [$x, $y] = $points[$index];
            $distance = \hypot($tip[0] - $x, $tip[1] - $y);
            if ($distance > 1e-9) {
                return [$tip, [($tip[0] - $x) / $distance, ($tip[1] - $y) / $distance]];
            }
        }

        return [$tip, [0.0, 1.0]];
    }
}
