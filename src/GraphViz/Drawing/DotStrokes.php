<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

/**
 * The lines an edge's `color` draws, as `dot` draws a colour list: side by side, 2 points
 * apart, each in its colour; or, where any colour has a weight after `;`, one after the other
 * along the edge, each as long as its weight asks, the colours without one sharing what is
 * left. Its arrowheads take the colour at their end along a weighted list; side by side, the
 * head takes the first colour and the tail the last.
 */
final class DotStrokes
{
    /** How far apart lines side by side run, in points. */
    private const float APART = 2.0;

    /**
     * @param list<array{float, float}> $points
     * @return list<array{list<array{float, float}>, string}> each line and its colour
     */
    public static function lines(array $points, string $color): array
    {
        $colors = self::parse($color);
        if (\count($colors) < 2) {
            return [[$points, $colors[0][0] ?? '#000000']];
        }
        if (!self::weighted($colors)) {
            $lines = [];
            foreach ($colors as $place => [$resolved]) {
                $lines[] = [self::beside($points, ($place - ((\count($colors) - 1) / 2)) * self::APART), $resolved];
            }

            return $lines;
        }

        return self::along($points, $colors);
    }

    /** @return array{string, string} the colour of the tail's arrowhead and of the head's */
    public static function ends(string $color): array
    {
        $colors = self::parse($color);
        $first = $colors[0][0] ?? '#000000';
        $last = $colors[\count($colors) - 1][0] ?? '#000000';

        return self::weighted($colors) ? [$first, $last] : [$last, $first];
    }

    /** @param list<array{string, ?float}> $colors */
    private static function weighted(array $colors): bool
    {
        foreach ($colors as [, $weight]) {
            if (null !== $weight) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{string, ?float}> each colour, resolved, and its weight, null for none */
    private static function parse(string $color): array
    {
        $colors = [];
        foreach (\explode(':', $color) as $part) {
            [$name, $weight] = \array_pad(\explode(';', $part, limit: 2), length: 2, value: null);
            if ('' === \trim((string) $name)) {
                continue;
            }
            $colors[] = [DotColors::resolve((string) $name, '#000000'), \is_numeric($weight) ? \max(0.0, \min(1.0, (float) $weight)) : null];
        }

        return $colors;
    }

    /**
     * A line moved sideways, square to its way at each point: an offset below zero moves a
     * line running down the screen toward the screen's left, as dot lists a colour list.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function beside(array $points, float $offset): array
    {
        $last = \count($points) - 1;
        $moved = [];
        foreach ($points as $index => [$x, $y]) {
            [$fromX, $fromY] = $points[\max(0, $index - 1)];
            [$toX, $toY] = $points[\min($last, $index + 1)];
            $length = \max(1e-9, \hypot($toX - $fromX, $toY - $fromY));
            $moved[] = [$x + ((($toY - $fromY) / $length) * $offset), $y - ((($toX - $fromX) / $length) * $offset)];
        }

        return $moved;
    }

    /**
     * @param list<array{float, float}> $points
     * @param list<array{string, ?float}> $colors
     * @return list<array{list<array{float, float}>, string}>
     */
    private static function along(array $points, array $colors): array
    {
        $lengths = [0.0];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $lengths[] = $lengths[$index - 1] + \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }
        $total = $lengths[\count($lengths) - 1];
        $lines = [];
        foreach (self::spans($colors) as [$resolved, $from, $to]) {
            $lines[] = [self::stretch($points, $lengths, $from * $total, $to * $total), $resolved];
        }

        return $lines;
    }

    /**
     * Where each colour of a list starts and ends, as parts of the whole: as much as its weight
     * after `;` asks, the colours without one sharing what is left; none past the whole.
     *
     * @return list<array{string, float, float}> each colour, resolved, and its start and end
     */
    public static function shares(string $color): array
    {
        return self::spans(self::parse($color));
    }

    /**
     * @param list<array{string, ?float}> $colors
     * @return list<array{string, float, float}>
     */
    private static function spans(array $colors): array
    {
        $given = \array_sum(\array_map(static fn (array $color): float => $color[1] ?? 0.0, $colors));
        $unweighted = \count(\array_filter($colors, static fn (array $color): bool => null === $color[1]));
        $share = $unweighted > 0 ? \max(0.0, 1 - $given) / $unweighted : 0.0;
        $spans = [];
        $from = 0.0;
        foreach ($colors as [$resolved, $weight]) {
            $to = \min(1.0, $from + ($weight ?? $share));
            if ($to > $from) {
                $spans[] = [$resolved, $from, $to];
            }
            $from = $to;
        }

        return $spans;
    }

    /**
     * The part of a line between two distances along it.
     *
     * @param list<array{float, float}> $points
     * @param list<float> $lengths each point's distance along the line
     * @return list<array{float, float}>
     */
    private static function stretch(array $points, array $lengths, float $start, float $end): array
    {
        $part = [self::at($points, $lengths, $start)];
        foreach ($points as $index => $point) {
            if ($lengths[$index] <= $start || $lengths[$index] >= $end) {
                continue;
            }
            $part[] = $point;
        }
        $part[] = self::at($points, $lengths, $end);

        return $part;
    }

    /**
     * @param list<array{float, float}> $points
     * @param list<float> $lengths
     * @return array{float, float}
     */
    private static function at(array $points, array $lengths, float $distance): array
    {
        $index = 1;
        $last = \count($points) - 1;
        while ($index < $last && $lengths[$index] < $distance) {
            ++$index;
        }
        $span = $lengths[$index] - $lengths[$index - 1];
        $t = $span > 0 ? \min(1.0, ($distance - $lengths[$index - 1]) / $span) : 0.0;

        return [$points[$index - 1][0] + (($points[$index][0] - $points[$index - 1][0]) * $t), $points[$index - 1][1] + (($points[$index][1] - $points[$index - 1][1]) * $t)];
    }
}
