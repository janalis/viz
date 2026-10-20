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
     * A fill: one colour, or, for a list, a gradient across from its first colour to its second,
     * as `dot` draws one — from left to right, or right to left when `gradientangle` points left.
     * A colour's weight after `;` is dropped.
     *
     * @return array{string, ?array{string, string}} the fill, and its gradient
     */
    public static function fill(string $value, string $default, string $angle): array
    {
        $colors = \array_values(\array_filter(
            \array_map(static fn (string $color): string => \trim(\explode(';', $color)[0]), \explode(':', $value)),
            static fn (string $color): bool => '' !== $color,
        ));
        $first = self::resolve($colors[0] ?? '', $default);
        if (\count($colors) < 2) {
            return [$first, null];
        }
        $gradient = [$first, self::resolve($colors[1], $default)];
        $leftward = \is_numeric($angle) && \cos(\deg2rad((float) $angle)) < 0;

        return [$first, $leftward ? [$gradient[1], $gradient[0]] : $gradient];
    }
}
