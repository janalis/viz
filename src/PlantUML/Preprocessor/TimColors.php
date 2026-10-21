<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

use Janalis\Viz\PlantUML\Style\PlantUmlColorNames;
use Janalis\Viz\Source\Unsupported;

/**
 * The colour functions of the preprocessor, as PlantUML computes them: through its `HSLColor`
 * in Java's single-precision floats, a colour written back as `#RRGGBB`, or with its alpha
 * first in lower case.
 */
final class TimColors
{
    /** @throws TimError for no colour; Unsupported for one that is not a single colour */
    public static function darken(string $color, int $ratio): string
    {
        [$h, $s, $l] = self::hsl(self::rgba($color));

        return self::written([...self::rgb($h, $s, self::f32($l - ($l * ($ratio / 100.0)))), 255]);
    }

    public static function lighten(string $color, int $ratio): string
    {
        [$h, $s, $l] = self::hsl(self::rgba($color));

        return self::written([...self::rgb($h, $s, self::f32($l + ($l * ($ratio / 100.0)))), 255]);
    }

    public static function isDark(string $color): bool
    {
        [$r, $g, $b] = self::rgba($color);

        return \intdiv(($r * 299) + ($g * 587) + ($b * 114), num2: 1000) < 128;
    }

    public static function reverse(string $color): string
    {
        [$r, $g, $b] = self::rgba($color);

        return self::written([255 - $r, 255 - $g, 255 - $b, 255]);
    }

    public static function hslColor(int $hue, int $saturation, int $lightness, ?int $alpha): string
    {
        $a = null === $alpha ? 1.0 : self::f32($alpha / 100.0);
        $rgb = self::rgb((float) $hue, (float) $saturation, (float) $lightness);

        return self::written([...$rgb, (int) \floor(self::f32(\max(0.0, \min(1.0, $a)) * 255.0) + 0.5)]);
    }

    /** @return array{int, int, int, int} */
    private static function rgba(string $color): array
    {
        $name = \str_starts_with($color, '#') ? \substr($color, offset: 1) : $color;
        $hex = self::hex($name);
        if (null !== $hex) {
            return $hex;
        }
        if (\in_array(\strtolower($name), ['transparent', 'background', 'automatic'], strict: true) || 1 === \preg_match('/^\?|[-\\\\|\/]/', $name)) {
            throw new Unsupported('The native PlantUML renderer leaves colour functions of '.$color.' to PlantUML');
        }
        $known = 1 === \preg_match('/^[a-zA-Z]+$/', $name) ? PlantUmlColorNames::NAMES[\strtolower($name)] ?? null : null;
        if (null === $known) {
            throw new TimError('No such color');
        }

        return self::hex(\substr($known, offset: 1)) ?? [0, 0, 0, 255];
    }

    /** @return ?array{int, int, int, int} a colour of one, three, six or eight hex digits */
    private static function hex(string $name): ?array
    {
        if (1 !== \preg_match('/^(?:[0-9a-f]|[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $name)) {
            return null;
        }
        $digits = match (\strlen($name)) {
            1 => \str_repeat($name, times: 6),
            3 => $name[0].$name[0].$name[1].$name[1].$name[2].$name[2],
            default => $name,
        };
        $bytes = \array_map(\hexdec(...), \str_split($digits, length: 2));

        return [(int) $bytes[0], (int) $bytes[1], (int) $bytes[2], (int) ($bytes[3] ?? 255)];
    }

    /**
     * @param array{int, int, int, int} $rgba
     * @return array{float, float, float} hue in degrees, saturation and lightness in hundredths
     */
    private static function hsl(array $rgba): array
    {
        [$r, $g, $b] = [self::f32($rgba[0] / 255.0), self::f32($rgba[1] / 255.0), self::f32($rgba[2] / 255.0)];
        $min = \min($r, $g, $b);
        $max = \max($r, $g, $b);
        $range = self::f32($max - $min);
        $h = match (true) {
            $max === $min => 0.0,
            $max === $r => self::f32(\fmod(self::f32(self::f32(self::f32(60 * self::f32($g - $b)) / $range) + 360), num2: 360.0)),
            $max === $g => self::f32(self::f32(self::f32(60 * self::f32($b - $r)) / $range) + 120),
            default => self::f32(self::f32(self::f32(60 * self::f32($r - $g)) / $range) + 240),
        };
        $l = self::f32(self::f32($max + $min) / 2);
        $s = match (true) {
            $max === $min => 0.0,
            $l <= self::f32(0.5) => self::f32($range / self::f32($max + $min)),
            default => self::f32($range / self::f32(self::f32(2 - $max) - $min)),
        };

        return [$h, self::f32($s * 100), self::f32($l * 100)];
    }

    /** @return array{int, int, int} */
    private static function rgb(float $h, float $s, float $l): array
    {
        $h = self::f32(self::f32(\fmod($h, num2: 360.0)) / 360);
        $s = self::f32(\max(0.0, \min(100.0, $s)) / 100);
        $l = self::f32(\max(0.0, \min(100.0, $l)) / 100);
        $q = $l < 0.5 ? self::f32($l * self::f32(1 + $s)) : self::f32(self::f32($l + $s) - self::f32($s * $l));
        $p = self::f32(self::f32(2 * $l) - $q);
        $third = self::f32(1.0 / 3.0);

        return [
            self::byte(self::hue($p, $q, self::f32($h + $third))),
            self::byte(self::hue($p, $q, $h)),
            self::byte(self::hue($p, $q, self::f32($h - $third))),
        ];
    }

    private static function hue(float $p, float $q, float $h): float
    {
        $h = $h < 0 ? self::f32($h + 1) : $h;
        $h = $h > 1 ? self::f32($h - 1) : $h;
        $step = self::f32(self::f32($q - $p) * 6);

        return match (true) {
            self::f32(6 * $h) < 1 => self::f32($p + self::f32($step * $h)),
            self::f32(2 * $h) < 1 => $q,
            self::f32(3 * $h) < 2 => self::f32($p + self::f32($step * self::f32(self::f32(2.0 / 3.0) - $h))),
            default => $p,
        };
    }

    /** A component of 0 to 1 as a byte, as Java's `Math.round` rounds it. */
    private static function byte(float $component): int
    {
        return (int) \floor(self::f32(\min(1.0, \max(0.0, $component)) * 255) + 0.5);
    }

    /** @param array{int, int, int, int} $rgba */
    private static function written(array $rgba): string
    {
        [$r, $g, $b, $a] = $rgba;
        if (0 === $a) {
            return 'transparent';
        }

        return 255 === $a ? \sprintf('#%02X%02X%02X', $r, $g, $b) : \sprintf('#%02x%02x%02x%02x', $a, $r, $g, $b);
    }

    /** A number as Java keeps a `float`. */
    private static function f32(float|int $value): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = \unpack('g', \pack('g', $value));

        return $unpacked[1];
    }
}
