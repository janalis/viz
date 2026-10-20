<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/**
 * Where a polygon's linear gradient runs, as dot lays one: through the centre of the
 * polygon's box, from one side to the other at its angle, reaching as far as the box's half
 * width and half height along the angle's cosine and sine; and how far along it a point falls.
 */
final class GradientAxis
{
    /**
     * @param list<array{float, float}> $points the polygon's
     * @param float $angle in degrees, counter-clockwise from left to right
     * @return array{array{float, float}, array{float, float}} where the first colour stands, and the second
     */
    public static function ends(array $points, float $angle): array
    {
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);
        [$x, $y] = [(\min($xs) + \max($xs)) / 2, (\min($ys) + \max($ys)) / 2];
        $radians = \deg2rad($angle);
        // Counter-clockwise, as on paper: up the screen, its y running down.
        [$across, $down] = [((\max($xs) - \min($xs)) / 2) * \cos($radians), ((\max($ys) - \min($ys)) / 2) * \sin($radians)];

        return [[$x - $across, $y + $down], [$x + $across, $y - $down]];
    }

    /**
     * How much of the second colour a point takes, from nought to one: the share of the way
     * along the axis it stands, or all or nothing either side of a split.
     *
     * @param array{array{float, float}, array{float, float}} $ends
     */
    public static function share(array $ends, ?float $split, float $x, float $y): float
    {
        [[$fromX, $fromY], [$toX, $toY]] = $ends;
        $length = ($toX - $fromX) ** 2 + ($toY - $fromY) ** 2;
        $along = $length > 0 ? \max(0.0, \min(1.0, ((($x - $fromX) * ($toX - $fromX)) + (($y - $fromY) * ($toY - $fromY))) / $length)) : 0.0;

        if (null === $split) {
            return $along;
        }

        return $along < $split ? 0.0 : 1.0;
    }

    /**
     * A radial gradient's focus, as parts of its box from its left and top: the centre, or, at
     * an angle, half the box out from it that way, as dot moves it.
     *
     * @return array{float, float}
     */
    public static function focus(float $angle): array
    {
        if (0.0 === $angle) {
            return [0.5, 0.5];
        }
        $radians = \deg2rad($angle);

        return [0.5 + (0.5 * \cos($radians)), 0.5 - (0.5 * \sin($radians))];
    }

    /**
     * How much of the second colour a point of a radial gradient takes: how far it stands from
     * the focus toward the circle three quarters of the box out from the centre, all in parts
     * of the box, so the circle is the box's ellipse.
     *
     * @param array{float, float, float, float} $box left, top, width, height
     * @param array{float, float} $focus
     */
    public static function radial(array $box, array $focus, float $x, float $y): float
    {
        [$left, $top, $width, $height] = $box;
        $d = [(($x - $left) / \max(1.0e-9, $width)) - $focus[0], (($y - $top) / \max(1.0e-9, $height)) - $focus[1]];
        $e = [0.5 - $focus[0], 0.5 - $focus[1]];
        // The circle through the point among those from the focus, t = 0, to the outer one, t = 1.
        $a = $e[0] ** 2 + $e[1] ** 2 - 0.75 ** 2;
        $de = ($d[0] * $e[0]) + ($d[1] * $e[1]);
        $dd = $d[0] ** 2 + $d[1] ** 2;
        $t = ($de - \sqrt(\max(0.0, $de ** 2 - ($a * $dd)))) / $a;

        return \max(0.0, \min(1.0, $t));
    }
}
