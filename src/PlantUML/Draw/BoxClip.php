<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;

/**
 * A link's path trimmed at the boxes it joins — or at the ovals and diamonds drawn in them,
 * where the node is one — the crossings found by bisection.
 */
final class BoxClip
{
    /**
     * A path trimmed to where it leaves one box and enters the other.
     *
     * @param list<array{float, float}> $points
     * @param array<string, string> $shapes `oval` or `diamond` by node name, for a node not drawn as a box
     * @return list<array{float, float}>
     */
    public static function clipped(array $points, GraphNode $from, GraphNode $to, array $shapes = []): array
    {
        $points = self::cut($points, $from, $shapes[$from->name] ?? 'box');

        return \array_reverse(self::cut(\array_reverse($points), $to, $shapes[$to->name] ?? 'box'));
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function cut(array $points, GraphNode $box, string $shape): array
    {
        $count = \count($points);
        $index = 0;
        while ($index < $count && self::inside($points[$index], $box, $shape)) {
            ++$index;
        }
        if (0 === $index || $count === $index) {
            return $points;
        }
        [$in, $out] = [$points[$index - 1], $points[$index]];
        for ($step = 0; $step < 16; ++$step) {
            $middle = [($in[0] + $out[0]) / 2, ($in[1] + $out[1]) / 2];
            [$in, $out] = self::inside($middle, $box, $shape) ? [$middle, $out] : [$in, $middle];
        }

        return [$out, ...\array_slice($points, $index)];
    }

    /** @param array{float, float} $point */
    public static function inside(array $point, GraphNode $box, string $shape): bool
    {
        if ($box->w <= 0 || $box->h <= 0) {
            return false;
        }
        // A circle of a radius about the node's centre, whatever the node's room around it.
        if (\str_starts_with($shape, 'circle:')) {
            $radius = \substr($shape, offset: 7);

            return \is_numeric($radius) && \hypot($point[0] - $box->x, $point[1] - $box->y) < (float) $radius;
        }
        [$x, $y] = [\abs($point[0] - $box->x) / ($box->w / 2), \abs($point[1] - $box->y) / ($box->h / 2)];

        return match ($shape) {
            'oval' => (($x * $x) + ($y * $y)) < 1,
            'diamond' => ($x + $y) < 1,
            default => $x < 1 && $y < 1,
        };
    }
}
