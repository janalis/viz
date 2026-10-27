<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/**
 * The smallest ellipse of a given flatness around points, as PlantUML's `ContainingEllipse` finds
 * it: the smallest circle around them, as its `SmallestEnclosingCircle` finds one, once their
 * heights are stretched by the flatness.
 */
final class ContainingEllipse
{
    /**
     * @param list<array{float, float}> $points
     * @param float $alpha how tall it is for its width
     * @return array{float, float, float} its radius across, and its centre
     */
    public static function around(array $points, float $alpha): array
    {
        $stretched = [];
        foreach ($points as [$x, $y]) {
            $point = [$x, $y / $alpha];
            if (!\in_array($point, $stretched, strict: true)) {
                $stretched[] = $point;
            }
        }
        $bound = $stretched;
        [$centre, $radius] = self::circle(\count($stretched), $stretched, 0, $bound);

        return [$radius, $centre[0], $centre[1] * $alpha];
    }

    /**
     * Welzl's recursion as `SmallestEnclosingCircle::findSec` runs it, the points it is bound
     * to passed through as it passes them.
     *
     * @param list<array{float, float}> $points
     * @param list<array{float, float}> $bound
     * @return array{array{float, float}, float}
     */
    private static function circle(int $count, array $points, int $bounds, array &$bound): array
    {
        $circle = match ($bounds) {
            0 => [[0.0, 0.0], 0.0],
            1 => [$bound[0], 0.0],
            2 => self::diameter($bound[0], $bound[1]),
            default => self::through($bound[0], $bound[1], $bound[2]),
        };
        if (3 === $bounds) {
            return $circle;
        }
        for ($i = 0; $i < $count; ++$i) {
            if (self::distance($points[$i], $circle[0]) <= $circle[1]) {
                continue;
            }
            $bound[$bounds] = $points[$i];
            $circle = self::circle($i, $points, $bounds + 1, $bound);
        }

        return $circle;
    }

    /**
     * @param array{float, float} $one
     * @param array{float, float} $two
     * @return array{array{float, float}, float}
     */
    private static function diameter(array $one, array $two): array
    {
        $centre = [($one[0] + $two[0]) / 2, ($one[1] + $two[1]) / 2];

        return [$centre, self::distance($one, $centre)];
    }

    /**
     * The circle through three points, as `Circle.getCircle` solves for it.
     *
     * @param array{float, float} $one
     * @param array{float, float} $two
     * @param array{float, float} $three
     * @return array{array{float, float}, float}
     */
    private static function through(array $one, array $two, array $three): array
    {
        if ($three[1] === $two[1]) {
            [$one, $two] = [$two, $one];
        }
        [[$x1, $y1], [$x2, $y2], [$x3, $y3]] = [$one, $two, $three];
        $x =
            (($x3 * $x3 * ($y1 - $y2)) + ((($x1 * $x1) + (($y1 - $y2) * ($y1 - $y3))) * ($y2 - $y3)) + ($x2 * $x2 * (-$y1 + $y3)))
            / (2 * (($x3 * ($y1 - $y2)) + ($x1 * ($y2 - $y3)) + ($x2 * (-$y1 + $y3))));
        $y = (($y2 + $y3) / 2) - ((($x3 - $x2) / ($y3 - $y2)) * ($x - (($x2 + $x3) / 2)));

        return [[$x, $y], self::distance([$x, $y], $one)];
    }

    /**
     * As Java's `Point2D.distance` takes it.
     *
     * @param array{float, float} $one
     * @param array{float, float} $two
     */
    private static function distance(array $one, array $two): float
    {
        [$dx, $dy] = [$one[0] - $two[0], $one[1] - $two[1]];

        return \sqrt(($dx * $dx) + ($dy * $dy));
    }
}
