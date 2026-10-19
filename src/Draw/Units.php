<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** The one conversion between points, which Graphviz and PlantUML lay out in, and drawing units of ten CSS pixels. */
final class Units
{
    /** Drawing units per point: a point is 96/72 CSS pixels, a unit ten. */
    public const float POINT = (96 / 72) / 10;

    /** A text scale for a font size in points: scale 1 is the backends' 16-pixel, 12-point text. */
    public static function fontScale(float $points): float
    {
        return $points / 12;
    }

    /** How wide a character cell and how tall a line of text are, in points, at a font size in points. */
    public static function cell(float $points): float
    {
        return self::fontScale($points) / self::POINT;
    }

    public static function line(float $points): float
    {
        return (2 * self::fontScale($points)) / self::POINT;
    }
}
