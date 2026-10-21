<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/** PlantUML's `Arrow`, the head its JSON and git diagrams draw at the end of a curve. */
final class PlantUmlArrow
{
    /**
     * From its base to its tip, its wings 0.4 of its length to either side of its base, its
     * back notched 0.3 of it in.
     *
     * @param array{float, float} $base
     * @param array{float, float} $tip
     * @return list<array{float, float}>
     */
    public static function head(array $base, array $tip): array
    {
        $length = \hypot($tip[0] - $base[0], $tip[1] - $base[1]);
        $angle = \atan2($tip[0] - $base[0], $tip[1] - $base[1]);
        $from = static fn (float $angle, float $distance): array => [$base[0] + ($distance * \sin($angle)), $base[1] + ($distance * \cos($angle))];
        $wing = $from($angle - (\M_PI / 2), $length * 0.4);

        return [$wing, $from($angle, $length * 0.3), $from($angle + (\M_PI / 2), $length * 0.4), $tip, $wing];
    }
}
