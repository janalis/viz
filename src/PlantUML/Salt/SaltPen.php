<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/** The lines, boxes and shapes salt draws, black and 1 thick unless told otherwise. */
final class SaltPen
{
    public static function line(float $x1, float $y1, float $x2, float $y2, string $color = '#000000', float $width = 1.0): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $color, strokeWidth: $width);
    }

    public static function box(float $x, float $y, float $width, float $height, string $fill = 'none', string $color = '#000000', float $thickness = 1.0): Mark
    {
        return ClassBox::rectangle($x, $y, $width, $height, $color, $fill, $thickness, 0.0);
    }

    /** @param list<array{float, float}> $points a shape filled with its line's colour, from a point */
    public static function shape(array $points, float $x, float $y, string $color = '#000000', float $width = 1.0): Mark
    {
        return new Mark(MarkKind::Polygon, \array_map(static fn (array $point): array => [$x + $point[0], $y + $point[1]], $points), stroke: $color, fill: $color, strokeWidth: $width);
    }

    /** A circle round a centre. */
    public static function circle(float $x, float $y, float $radius, string $fill, float $width): Mark
    {
        $points = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $points[] = [$x + ($radius * \cos($angle)), $y + ($radius * \sin($angle))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: '#000000', fill: $fill, strokeWidth: $width);
    }
}
