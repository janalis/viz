<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * An Open Iconic icon, `<&name>`, drawn as PlantUML draws one: its 8 by 8 paths scaled to two
 * thirds of the text's size, filled even-odd in the text's colour, 1 into the room it takes,
 * its foot 0.039 of the size above the line's baseline, as measured on PlantUML's output.
 */
final class PlantUmlIcon
{
    /** @var ?array<string, list<string>> each icon's paths, by name */
    private static ?array $icons = null;

    /** The room an icon takes along its line: two thirds of the size, and 2. */
    public static function width(float $size): float
    {
        return ((2 * $size) / 3) + 2;
    }

    /** @return list<Mark> the icon in the room it takes from a point along a baseline; nothing for an icon PlantUML does not know */
    public static function marks(string $name, float $left, float $baseline, float $size, string $color): array
    {
        $scale = $size / 12;
        [$x, $y] = [$left + 1, $baseline - (0.039 * $size) - ((2 * $size) / 3)];
        $marks = [];
        foreach (self::icons()[$name] ?? [] as $data) {
            $outlines = \array_map(static fn (array $outline): array => \array_map(static fn (array $point): array => [
                $x + ($point[0] * $scale),
                $y + ($point[1] * $scale),
            ], $outline), SvgPath::outlines($data));
            // Each of the icons' paths draws one outline at least.
            $marks[] = new Mark(MarkKind::Polygon, $outlines[0], stroke: 'none', fill: $color, strokeWidth: 0.0, subpaths: \array_slice($outlines, offset: 1));
        }

        return $marks;
    }

    /** @return array<string, list<string>> */
    private static function icons(): array
    {
        if (null === self::$icons) {
            /** @var array<string, list<string>> $icons */
            $icons = \json_decode((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/openiconic.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$icons = $icons;
        }

        return self::$icons;
    }
}
