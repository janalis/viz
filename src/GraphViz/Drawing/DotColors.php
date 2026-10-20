<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Color\ColorValue;
use Janalis\Viz\Color\CssColorNames;

/** DOT colours as hex: Graphviz's X11 names first, then CSS names, `#rgb`, `#rrggbb` and HSV; `transparent` and `none` as none. */
final class DotColors
{
    public static function resolve(string $value, string $default): string
    {
        $value = \trim($value);
        if ('' === $value) {
            return $default;
        }
        if (\in_array(\strtolower($value), ['none', 'transparent', 'invis'], strict: true)) {
            return 'none';
        }

        return ColorValue::normalize($value) ?? CssColorNames::NAMES[\strtolower(\explode(':', $value)[0])] ?? $default;
    }

    /**
     * A fill: one colour, or, for a list, a gradient from its first colour to its second, as
     * `dot` draws one, and where a weight after `;` splits it sharply: at the first colour's
     * weight, or short of the second's by its weight. Colours past the second are dropped.
     *
     * @return array{string, ?array{string, string}, ?float} the fill, its gradient, and where it splits
     */
    public static function fill(string $value, string $default): array
    {
        $colors = [];
        foreach (\explode(':', $value) as $part) {
            [$name, $weight] = \array_pad(\explode(';', $part, limit: 2), length: 2, value: '');
            if ('' === \trim($name)) {
                continue;
            }
            $weight = \trim($weight);
            $colors[] = [self::resolve(\trim($name), $default), \is_numeric($weight) ? \max(0.0, \min(1.0, (float) $weight)) : null];
        }
        $first = $colors[0][0] ?? $default;
        if (\count($colors) < 2) {
            return [$first, null, null];
        }
        $second = $colors[1][1];
        $split = $colors[0][1] ?? (null === $second ? null : 1 - $second);

        return [$first, [$first, $colors[1][0]], $split];
    }

    /** A `gradientangle` in degrees, counter-clockwise from across to the right; none, nought. */
    public static function angle(string $angle): float
    {
        $angle = \trim($angle);

        return \is_numeric($angle) ? \fmod((float) $angle, num2: 360.0) : 0.0;
    }
}
