<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\FillRule;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\PlantUmlHsluv;

/**
 * One of PlantUML's emoji, the Twemoji pictures it bundles, drawn as its `SvgNanoParser` draws
 * one: each path, circle and ellipse in turn, filled in its colour, else its group's, else
 * black; a path filled as SVG fills one, its outlines winding; a circle edged in its colour
 * too; turned as `rotate(…)` or `matrix(…)` writes it; its 36 by 36 view scaled to a size. Where
 * a colour is forced, each colour is its grey, or, unless that colour is a grey itself, that
 * grey lightening the forced colour in HSLuv from the picture's darkest grey up.
 */
final class PlantUmlEmoji
{
    /** How many straight steps a circle or an ellipse is drawn in. */
    private const int STEPS = 32;

    /** @var ?array<string, string> each emoji's code, by its code and its name */
    private static ?array $codes = null;

    /** @var array<string, list<string>> each emoji's elements, by code */
    private static array $elements = [];

    /** An emoji's code, by its code or its name as PlantUML knows them, in any case; null for one it does not know. */
    public static function code(string $name): ?string
    {
        if (null === self::$codes) {
            self::$codes = [];
            foreach (\explode("\n", \trim((string) \file_get_contents(self::directory().'emoji.txt'))) as $line) {
                $names = \explode(';', $line, limit: 2);
                foreach ($names as $known) {
                    self::$codes[$known] = $names[0];
                }
            }
        }

        return self::$codes[\strtolower($name)] ?? null;
    }

    /**
     * The colour an emoji is forced to as PlantUML forces one: black's written forms the text's
     * own, one it does not know none.
     *
     * @param ?string $written as written, null for none
     */
    public static function forced(?string $written, string $text): ?string
    {
        $forced = match (true) {
            null === $written => null,
            \in_array(\strtolower($written), ['#0', '#000', '#black'], strict: true) => $text,
            default => PlantUmlColor::parse($written),
        };

        return 'none' === $forced ? null : $forced;
    }

    /**
     * @param ?string $forced the colour its colours are shaded towards, as hex; null for its own
     * @return list<Mark> the emoji with its top left at a point
     */
    public static function marks(string $code, float $x, float $y, float $size, ?string $forced = null): array
    {
        $elements = self::elements($code);
        $least = null === $forced ? 0 : \min(\array_map(self::own(...), $elements));
        $scale = $size / 36;
        $base = [$scale, 0.0, 0.0, $scale, $x, $y];
        /** @var list<string> $groups */
        $groups = [];
        $marks = [];
        foreach ($elements as $element) {
            if (\str_starts_with($element, '</g>')) {
                \array_shift($groups);
                continue;
            }
            if (\str_starts_with($element, '<g')) {
                \array_unshift($groups, $element);
                continue;
            }
            $fill = self::fill($element, $groups, $forced, $least);
            if ('none' === $fill) {
                continue;
            }
            $matrix = self::times($base, self::transform($element));
            $marks[] = match (true) {
                \str_starts_with($element, '<path ') => self::path($element, $matrix, $fill),
                \str_starts_with($element, '<circle ') => self::circle($element, $matrix, $fill),
                default => self::ellipse($element, $matrix, $fill),
            };
        }

        return $marks;
    }

    /** @param array{float, float, float, float, float, float} $matrix */
    private static function path(string $element, array $matrix, string $fill): Mark
    {
        $outlines = \array_map(static fn (array $outline): array => \array_map(static fn (array $point): array => self::apply(
            $matrix,
            $point,
        ), $outline), SvgPath::outlines((string) self::attribute($element, 'd')));

        return new Mark(MarkKind::Polygon, $outlines[0] ?? [], stroke: 'none', fill: $fill, strokeWidth: 0.0, subpaths: \array_slice($outlines, offset: 1), fillRule: FillRule::NonZero);
    }

    /**
     * A circle, as PlantUML draws one: its centre and radius scaled along each axis as the
     * transform scales them there, turning aside, and edged in its colour.
     *
     * @param array{float, float, float, float, float, float} $matrix
     */
    private static function circle(string $element, array $matrix, string $fill): Mark
    {
        [$cx, $cy, $r] = [self::number($element, 'cx'), self::number($element, 'cy'), self::number($element, 'r')];
        $centre = [$matrix[4] + ($cx * $matrix[0]), $matrix[5] + ($cy * $matrix[3])];

        return new Mark(MarkKind::Polygon, self::round($centre, [\abs($r * $matrix[0]), \abs($r * $matrix[3])], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]), stroke: $fill, fill: $fill, strokeWidth: 0.5);
    }

    /** @param array{float, float, float, float, float, float} $matrix */
    private static function ellipse(string $element, array $matrix, string $fill): Mark
    {
        $centre = [self::number($element, 'cx'), self::number($element, 'cy')];

        return new Mark(MarkKind::Polygon, self::round($centre, [self::number($element, 'rx'), self::number($element, 'ry')], $matrix), stroke: 'none', fill: $fill, strokeWidth: 0.0);
    }

    /**
     * @param array{float, float} $centre
     * @param array{float, float} $radii
     * @param array{float, float, float, float, float, float} $matrix
     * @return list<array{float, float}>
     */
    private static function round(array $centre, array $radii, array $matrix): array
    {
        return \array_map(
            static fn (int $step): array => self::apply($matrix, [
                $centre[0] + ($radii[0] * \cos((2 * \M_PI * $step) / self::STEPS)),
                $centre[1] + ($radii[1] * \sin((2 * \M_PI * $step) / self::STEPS)),
            ]),
            \range(0, self::STEPS - 1),
        );
    }

    /**
     * An element's colour: its own, else its nearest group's, else the forced one or black;
     * shaded where one is forced.
     *
     * @param list<string> $groups
     */
    private static function fill(string $element, array $groups, ?string $forced, int $least): string
    {
        $written = self::written($element);
        foreach ($groups as $group) {
            $written ??= self::written($group);
        }
        if (null === $written) {
            return $forced ?? '#000000';
        }
        $color = PlantUmlColor::parse($written) ?? '#ffffff';
        if (null === $forced || 'none' === $color) {
            return $color;
        }
        $gray = self::gray($color);
        if (1 === \count(\array_unique(\str_split(\substr($forced, offset: 1), length: 2)))) {
            return \sprintf('#%02x%02x%02x', $gray, $gray, $gray);
        }
        [$r, $g, $b] = self::rgb($forced);
        [$h, $s, $l] = PlantUmlHsluv::fromRgb([$r / 256.0, $g / 256.0, $b / 256.0]);
        $rgb = PlantUmlHsluv::toRgb([$h, $s, $l + ((100 - $l) * (($gray - $least) / 256.0))]);

        return \vsprintf('#%02x%02x%02x', \array_map(static fn (float $channel): int => \max(0, \min(255, (int) (255 * $channel))), $rgb));
    }

    /** The grey of an element's own colour, as PlantUML finds the darkest of a picture's; 255 for none. */
    private static function own(string $element): int
    {
        $written = self::written($element);
        $color = null === $written ? null : PlantUmlColor::parse($written);

        return null === $color || 'none' === $color ? 255 : self::gray($color);
    }

    /** A colour's grey, as PlantUML's YIQ weighting takes it. */
    private static function gray(string $color): int
    {
        [$r, $g, $b] = self::rgb($color);

        return \intdiv(($r * 299) + ($g * 587) + ($b * 114), num2: 1000);
    }

    /** @return array{int, int, int} a hex colour's channels */
    private static function rgb(string $color): array
    {
        $channel = static fn (int $at): int => (int) \hexdec(\substr($color, $at, length: 2));

        return [$channel(1), $channel(3), $channel(5)];
    }

    /** An element's colour as written: its `fill`, or its style's. */
    private static function written(string $element): ?string
    {
        $style = self::attribute($element, 'style');
        $match = [];

        return self::attribute($element, 'fill') ?? (null !== $style && 1 === \preg_match('/fill:([^;"]+)/', $style, $match) ? $match[1] : null);
    }

    /**
     * An element's transform, as a matrix: `rotate(angle x y)` or `matrix(…)`; none otherwise.
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function transform(string $element): array
    {
        $transform = (string) self::attribute($element, 'transform');
        $match = [];
        if (1 === \preg_match('/rotate\(([-.0-9]+)[ ,]+([-.0-9]+)[ ,]+([-.0-9]+)\)/', $transform, $match)) {
            [$angle, $x, $y] = [\deg2rad(self::float($match[1])), self::float($match[2]), self::float($match[3])];
            [$cos, $sin] = [\cos($angle), \sin($angle)];

            return [$cos, $sin, -$sin, $cos, $x - ($x * $cos) + ($y * $sin), $y - ($x * $sin) - ($y * $cos)];
        }
        $number = '([-.0-9]+)[ ,]+';
        if (1 === \preg_match('/matrix\('.\str_repeat($number, times: 5).'([-.0-9]+)\)/', $transform, $match)) {
            return [self::float($match[1]), self::float($match[2]), self::float($match[3]), self::float($match[4]), self::float($match[5]), self::float($match[6])];
        }

        return [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    }

    /**
     * One transform after another: the second's applied first.
     *
     * @param array{float, float, float, float, float, float} $m
     * @param array{float, float, float, float, float, float} $n
     * @return array{float, float, float, float, float, float}
     */
    private static function times(array $m, array $n): array
    {
        return [
            ($m[0] * $n[0]) + ($m[2] * $n[1]),
            ($m[1] * $n[0]) + ($m[3] * $n[1]),
            ($m[0] * $n[2]) + ($m[2] * $n[3]),
            ($m[1] * $n[2]) + ($m[3] * $n[3]),
            ($m[0] * $n[4]) + ($m[2] * $n[5]) + $m[4],
            ($m[1] * $n[4]) + ($m[3] * $n[5]) + $m[5],
        ];
    }

    /**
     * @param array{float, float, float, float, float, float} $matrix
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private static function apply(array $matrix, array $point): array
    {
        return [($matrix[0] * $point[0]) + ($matrix[2] * $point[1]) + $matrix[4], ($matrix[1] * $point[0]) + ($matrix[3] * $point[1]) + $matrix[5]];
    }

    /** An attribute's value, as PlantUML finds it: the first `name="…"` in the element. */
    private static function attribute(string $element, string $name): ?string
    {
        $match = [];

        return 1 === \preg_match('/'.$name.'="([^"]*)"/', \str_replace('id="', replace: 'ID="', subject: $element), $match) ? $match[1] : null;
    }

    private static function number(string $element, string $name): float
    {
        return self::float((string) self::attribute($element, $name));
    }

    /** A number as written; nothing for one that is none. */
    private static function float(string $value): float
    {
        return \is_numeric($value) ? (float) $value : 0.0;
    }

    /** @return list<string> the emoji's paths, groups, circles and ellipses, in order, each as written */
    private static function elements(string $code): array
    {
        if (\array_key_exists($code, self::$elements)) {
            return self::$elements[$code];
        }
        $found = [];
        \preg_match_all('/<(?:path|g|circle|ellipse)\b[^<>]*>|<\/g>/', (string) \file_get_contents(self::directory().$code.'.svg'), $found);

        return self::$elements[$code] = \array_values($found[0]);
    }

    private static function directory(): string
    {
        return __DIR__.'/../../../resources/plantuml/emoji/';
    }
}
