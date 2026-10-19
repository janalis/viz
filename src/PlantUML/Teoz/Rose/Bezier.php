<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** A cubic Bézier curve as points along it, for the shapes PlantUML draws with curves. */
final class Bezier
{
    private const int STEPS = 8;

    /**
     * @param array{float, float} $start
     * @param array{float, float} $control1
     * @param array{float, float} $control2
     * @param array{float, float} $end
     * @return list<array{float, float}> the curve from its start to its end
     */
    public static function points(array $start, array $control1, array $control2, array $end): array
    {
        $points = [];
        for ($step = 0; $step <= self::STEPS; ++$step) {
            $t = $step / self::STEPS;
            $u = 1 - $t;
            $points[] = [
                (($u ** 3) * $start[0]) + (3 * $u * $u * $t * $control1[0]) + (3 * $u * $t * $t * $control2[0]) + (($t ** 3) * $end[0]),
                (($u ** 3) * $start[1]) + (3 * $u * $u * $t * $control1[1]) + (3 * $u * $t * $t * $control2[1]) + (($t ** 3) * $end[1]),
            ];
        }

        return $points;
    }
}
