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

    /**
     * A mark laid out in one unit, such as points, scaled into another: its points, its
     * strokes and dashes, and a text mark's size.
     */
    public static function scaled(Mark $mark, float $scale): Mark
    {
        return $mark->mapped(static fn (array $point): array => [$point[0] * $scale, $point[1] * $scale])->with([
            'strokeWidth' => $mark->strokeWidth * $scale,
            'fontScale' => MarkKind::Text === $mark->kind ? $mark->fontScale * $scale : $mark->fontScale,
            'dashes' => null === $mark->dashes ? null : \array_map(static fn (float $length): float => $length * $scale, $mark->dashes),
            'slots' => null === $mark->slots ? null : \array_map(static fn (float $slot): float => $slot * $scale, $mark->slots),
        ]);
    }

    /** A mark moved by an offset, in its own unit. */
    public static function moved(Mark $mark, float $x, float $y): Mark
    {
        return $mark->mapped(static fn (array $point): array => [$point[0] + $x, $point[1] + $y]);
    }

    /**
     * A mark turned a quarter counter-clockwise with a drawing of a width, its top now on its
     * left: each point at its distance down, across, and at what was left of the drawing's
     * width, down; text and gradients turned with it.
     */
    public static function turned(Mark $mark, float $width): Mark
    {
        return $mark->mapped(static fn (array $point): array => [$point[1], $width - $point[0]])->with([
            'angle' => $mark->angle - 90.0,
            'gradientAngle' => $mark->gradientAngle + 90.0,
        ]);
    }
}
