<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;

/**
 * The positions a laid-out graph carries, as a layout tool writes them, in points with y up:
 * a node's `pos`, `x,y` and a `!` it may end in; an edge's `pos`, its Bézier control points,
 * after `s,x,y` and `e,x,y` for its arrowheads' tips where it has them; an edge's `lp`.
 */
final class DotPositions
{
    /** @return array<string, array{float, float}> each placed node's centre, by name */
    public static function nodes(DotGraph $graph): array
    {
        $positions = [];
        foreach ($graph->nodes as $node) {
            $point = self::point(\rtrim($node->attribute('pos'), characters: '!'));
            if (null === $point) {
                continue;
            }
            $positions[$node->name] = $point;
        }

        return $positions;
    }

    /** @return array<int, array{?array{float, float}, list<array{float, float}>, ?array{float, float}}> each edge's spline, by index, where it has a whole one */
    public static function edges(DotGraph $graph): array
    {
        $curves = [];
        foreach ($graph->edges as $index => $edge) {
            [$start, $end, $controls] = [null, null, []];
            foreach (\explode(' ', (string) \preg_replace('/\s+/', replacement: ' ', subject: \trim($edge->attribute('pos')))) as $token) {
                [$start, $end, $controls] = match (true) {
                    \str_starts_with($token, 's,') => [self::point(\substr($token, offset: 2)), $end, $controls],
                    \str_starts_with($token, 'e,') => [$start, self::point(\substr($token, offset: 2)), $controls],
                    default => [$start, $end, [...$controls, self::point($token)]],
                };
            }
            $controls = \array_values(\array_filter($controls, static fn (?array $point): bool => null !== $point));
            // A whole spline is cubic pieces end to end: one control point, then three for each piece.
            if (\count($controls) < 4 || 1 !== (\count($controls) % 3)) {
                continue;
            }
            $curves[$index] = [$start, $controls, $end];
        }

        return $curves;
    }

    /** @return array<int, array{float, float}> each edge label's centre, by index, where it has one */
    public static function labels(DotGraph $graph): array
    {
        $labels = [];
        foreach ($graph->edges as $index => $edge) {
            $point = self::point($edge->attribute('lp'));
            if (null === $point) {
                continue;
            }
            $labels[$index] = $point;
        }

        return $labels;
    }

    /** @return ?array{float, float} */
    private static function point(string $text): ?array
    {
        $parts = \array_map(\trim(...), \explode(',', \trim($text)));
        if (2 !== \count($parts)) {
            return null;
        }
        [$x, $y] = $parts;

        return \is_numeric($x) && \is_numeric($y) ? [(float) $x, (float) $y] : null;
    }
}
