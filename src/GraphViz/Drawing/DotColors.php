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
}
