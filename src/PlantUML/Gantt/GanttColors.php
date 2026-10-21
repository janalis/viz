<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `HColorSimple.unlinear`: one colour turned towards another in HSL by the cube of a completion. */
final class GanttColors
{
    public static function blend(?string $from, ?string $to, int $completion): ?string
    {
        if (0 === $completion || null === $from || null === $to || 'none' === $from || 'none' === $to) {
            return $from;
        }
        if ($completion >= 100) {
            return $to;
        }
        $factor = ($completion / 100) ** 3;
        $a = self::hsl($from);
        $b = self::hsl($to);

        return self::rgb($a[0] + (($b[0] - $a[0]) * $factor), $a[1] + (($b[1] - $a[1]) * $factor), $a[2] + (($b[2] - $a[2]) * $factor));
    }

    /** @return array{float, float, float} hue in degrees, saturation and lightness in percent */
    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = \array_map(static fn (string $part): float => \hexdec($part) / 255, \str_split(\substr($hex, offset: 1, length: 6), length: 2));
        $max = \max($r, $g, $b);
        $min = \min($r, $g, $b);
        $l = ($max + $min) / 2;
        $h = match (true) {
            $max === $min => 0.0,
            $max === $r => \fmod(((60 * ($g - $b)) / ($max - $min)) + 360, num2: 360),
            $max === $g => ((60 * ($b - $r)) / ($max - $min)) + 120,
            default => ((60 * ($r - $g)) / ($max - $min)) + 240,
        };
        $s = match (true) {
            $max === $min => 0.0,
            $l <= 0.5 => ($max - $min) / ($max + $min),
            default => ($max - $min) / (2 - $max - $min),
        };

        return [$h, $s * 100, $l * 100];
    }

    private static function rgb(float $h, float $s, float $l): string
    {
        [$h, $s, $l] = [\fmod($h, num2: 360) / 360, $s / 100, $l / 100];
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($s * $l);
        $p = (2 * $l) - $q;
        $channel = static function (float $t) use ($p, $q): int {
            $t = match (true) {
                $t < 0 => $t + 1,
                $t > 1 => $t - 1,
                default => $t,
            };
            $value = match (true) {
                (6 * $t) < 1 => $p + (($q - $p) * 6 * $t),
                (2 * $t) < 1 => $q,
                (3 * $t) < 2 => $p + (($q - $p) * 6 * ((2 / 3) - $t)),
                default => $p,
            };

            return (int) \round(\min(1.0, \max(0.0, $value)) * 255);
        };

        return \sprintf('#%02x%02x%02x', $channel($h + (1 / 3)), $channel($h), $channel($h - (1 / 3)));
    }
}
