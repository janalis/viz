<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/** A rectangle with rounded corners, as PlantUML's `URectangle` draws one `rounded`. */
final class RoundedBox
{
    /** @return list<array{float, float}> its outline, each corner a quarter circle of a radius */
    public static function points(float $x, float $y, float $width, float $height, float $radius): array
    {
        $radius = \min($radius, $width / 2, $height / 2);
        $points = [];
        foreach ([[$x + $width - $radius, $y + $radius, -90], [$x + $width - $radius, $y + $height - $radius, 0], [$x + $radius, $y + $height - $radius, 90], [$x + $radius, $y + $radius, 180]] as [
            $cx,
            $cy,
            $start,
        ]) {
            for ($step = 0; $step <= 4; ++$step) {
                $angle = \deg2rad($start + ($step * 22.5));
                $points[] = [$cx + ($radius * \cos($angle)), $cy + ($radius * \sin($angle))];
            }
        }

        return $points;
    }
}
