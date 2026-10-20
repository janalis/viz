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
}
