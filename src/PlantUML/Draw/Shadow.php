<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A shape's shadow, as PlantUML casts one when its style's `Shadowing` is set: a darkened copy
 * of each filled outline, its distance down and right, drawn before it — and the room
 * PlantUML's limit finder gives it, twice that distance past the shape.
 */
final class Shadow
{
    /**
     * @param list<Mark> $marks a shape's, its outlines among them
     * @return list<Mark> its shadow, then the shape
     */
    public static function cast(array $marks, float $distance): array
    {
        if ($distance <= 0.0) {
            return $marks;
        }
        $shadows = [];
        foreach ($marks as $mark) {
            if (MarkKind::Polygon !== $mark->kind || 'none' === $mark->fill) {
                continue;
            }
            $points = \array_map(static fn (array $point): array => [$point[0] + $distance, $point[1] + $distance], $mark->points);
            $shadows[] = new Mark(MarkKind::Polygon, $points, stroke: 'none', fill: '#000000', strokeWidth: 0.0, fillOpacity: 0.2);
            // As the limit finder counts a shadowed shape, 1 short of its far sides as a rectangle, and twice the distance past.
            $corner = [\max(\array_column($mark->points, 0)) + (2 * $distance) - 1, \max(\array_column($mark->points, 1)) + (2 * $distance) - 1];
            $shadows[] = new Mark(MarkKind::Polyline, [$corner, $corner], stroke: 'none', strokeWidth: 0.0);
        }

        return [...$shadows, ...$marks];
    }
}
