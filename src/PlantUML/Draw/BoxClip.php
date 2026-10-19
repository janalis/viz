<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;

/** A link's path trimmed at the boxes it joins, the crossings found by bisection. */
final class BoxClip
{
    /**
     * A path trimmed to where it leaves one box and enters the other.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    public static function clipped(array $points, GraphNode $from, GraphNode $to): array
    {
        $points = self::cut($points, $from);

        return \array_reverse(self::cut(\array_reverse($points), $to));
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function cut(array $points, GraphNode $box): array
    {
        $count = \count($points);
        $index = 0;
        while ($index < $count && self::inside($points[$index], $box)) {
            ++$index;
        }
        if (0 === $index || $count === $index) {
            return $points;
        }
        [$in, $out] = [$points[$index - 1], $points[$index]];
        for ($step = 0; $step < 16; ++$step) {
            $middle = [($in[0] + $out[0]) / 2, ($in[1] + $out[1]) / 2];
            [$in, $out] = self::inside($middle, $box) ? [$middle, $out] : [$in, $middle];
        }

        return [$out, ...\array_slice($points, $index)];
    }

    /** @param array{float, float} $point */
    private static function inside(array $point, GraphNode $box): bool
    {
        return \abs($point[0] - $box->x) < ($box->w / 2) && \abs($point[1] - $box->y) < ($box->h / 2);
    }
}
