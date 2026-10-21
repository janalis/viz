<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

/**
 * PlantUML colours as hex: `#` optional, one hex digit for a grey, three or six digits, eight
 * with an alpha that is dropped, or a colour name; `transparent` and `background` are none.
 * A gradient (`a-b`, `a|b`, `a/b`, `a\b`) is drawn as its first colour.
 */
final class PlantUmlColor
{
    public static function parse(string $value): ?string
    {
        $value = \ltrim(\trim($value), characters: '#');
        if (\in_array(\strtolower($value), ['transparent', 'background', 'none'], strict: true)) {
            return 'none';
        }
        $simple = self::simple($value);
        if (null !== $simple) {
            return $simple;
        }
        $parts = \explode("\0", (string) \preg_replace('/[-\\\\|\/]/', replacement: "\0", subject: $value, limit: 1));

        return 2 === \count($parts) && null !== self::simple($parts[1]) ? self::simple($parts[0]) : null;
    }

    private static function simple(string $value): ?string
    {
        $value = \ltrim($value, characters: '#');
        if (1 !== \preg_match('/^[0-9a-f]+$/i', $value)) {
            return PlantUmlColorNames::NAMES[\strtolower($value)] ?? null;
        }

        return match (\strlen($value)) {
            1 => '#'.\str_repeat(\strtolower($value), times: 6),
            3 => '#'.\strtolower($value[0].$value[0].$value[1].$value[1].$value[2].$value[2]),
            6 => '#'.\strtolower($value),
            8 => '#'.\strtolower(\substr($value, offset: 0, length: 6)),
            default => PlantUmlColorNames::NAMES[\strtolower($value)] ?? null,
        };
    }
}
