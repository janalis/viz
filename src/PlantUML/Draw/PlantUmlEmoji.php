<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/** One of PlantUML's emoji, drawn as its paths are: each filled in its colour, its 36 by 36 view scaled to a size. */
final class PlantUmlEmoji
{
    /** @var array<string, list<array{string, list<list<array{float, float}>>}>> each emoji's paths, their colour and outlines, by code */
    private static array $paths = [];

    /** @return list<Mark> the emoji with its top left at a point */
    public static function marks(string $code, float $x, float $y, float $size): array
    {
        $scale = $size / 36;
        $marks = [];
        foreach (self::paths($code) as [$fill, $outlines]) {
            foreach ($outlines as $outline) {
                $marks[] = new Mark(
                    MarkKind::Polygon,
                    \array_map(static fn (array $point): array => [$x + ($point[0] * $scale), $y + ($point[1] * $scale)], $outline),
                    stroke: 'none',
                    fill: $fill,
                    strokeWidth: 0.0,
                );
            }
        }

        return $marks;
    }

    /** @return list<array{string, list<list<array{float, float}>>}> */
    private static function paths(string $code): array
    {
        if (\array_key_exists($code, self::$paths)) {
            return self::$paths[$code];
        }
        $svg = (string) \file_get_contents(__DIR__.'/../../../resources/plantuml/emoji/'.$code.'.svg');
        $found = [];
        \preg_match_all('/<path\b([^>]*)\/?>/', $svg, $found);
        $paths = [];
        foreach ($found[1] as $attributes) {
            $fill = [];
            $data = [];
            \preg_match('/\bfill="([^"]*)"/', $attributes, $fill);
            \preg_match('/\bd="([^"]*)"/', $attributes, $data);
            $paths[] = [PlantUmlColor::parse($fill[1] ?? '#000000') ?? '#000000', SvgPath::outlines($data[1] ?? '')];
        }

        return self::$paths[$code] = $paths;
    }
}
