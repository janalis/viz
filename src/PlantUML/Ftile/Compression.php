<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * PlantUML's compression of an activity diagram across or down: every gap between the room its
 * shapes take — lines take none — longer than 10 shrinks to 10. Rectangles and lines stretch
 * point by point; other shapes move with the point they were drawn from.
 */
final readonly class Compression
{
    private const float MARGIN = 5.0;

    /** @param list<array{float, float}> $gaps the stretches taken out */
    private function __construct(
        private array $gaps,
    ) {}

    /**
     * The shapes compressed across.
     *
     * @param list<Shape> $shapes
     * @return list<Shape>
     */
    public static function across(array $shapes): array
    {
        $slots = [];
        foreach ($shapes as $shape) {
            // As PlantUML, centred text takes no room across; compressed across, it is text like any other.
            if (ShapeKind::Centered === $shape->kind) {
                continue;
            }
            \array_push($slots, ...self::slots($shape, $shape->ignoreX, $shape->minX, $shape->maxX));
        }
        $compression = new self(self::gaps($slots));
        $identity = static fn (float $v): float => $v;

        return \array_map(static fn (Shape $shape): Shape => match ($shape->kind) {
            ShapeKind::Rectangle, ShapeKind::Line => $shape->mapped($compression->transform(...), $identity),
            ShapeKind::Centered => $compression->centered($shape),
            default => $shape->moved($compression->transform($shape->anchor()[0]) - $shape->anchor()[0], 0.0),
        }, $shapes);
    }

    /**
     * The shapes compressed down.
     *
     * @param list<Shape> $shapes
     * @return list<Shape>
     */
    public static function down(array $shapes): array
    {
        $slots = [];
        foreach ($shapes as $shape) {
            \array_push($slots, ...self::slots($shape, $shape->ignoreY, $shape->minY, $shape->maxY));
        }
        $compression = new self(self::gaps($slots));
        $identity = static fn (float $v): float => $v;

        return \array_map(static fn (Shape $shape): Shape => match ($shape->kind) {
            ShapeKind::Rectangle, ShapeKind::Line => $shape->mapped($identity, $compression->transform(...)),
            default => $shape->moved(0.0, $compression->transform($shape->anchor()[1]) - $shape->anchor()[1]),
        }, $shapes);
    }

    /** Centred text centred across its width compressed, as PlantUML's compressing graphics centre it. */
    private function centered(Shape $shape): Shape
    {
        [$x] = $shape->anchor();
        $left = $this->transform($x);
        $room = $this->transform($x + $shape->span) - $left;

        return $shape->moved($left + (($room - ($shape->maxX - $shape->minX)) / 2) - $shape->minX, 0.0);
    }

    /** Where a point goes, the gaps before it taken out. */
    public function transform(float $v): float
    {
        $delta = 0.0;
        foreach ($this->gaps as [$start, $end]) {
            if ($start > $v) {
                continue;
            }
            $delta += $v > $end ? $end - $start : $v - $start;
        }

        return $v - $delta;
    }

    /**
     * The room a shape takes one way: none for a line, nor for a shape compression skips — but
     * a rectangle it skips keeps its sides, 2 each.
     *
     * @return list<array{float, float}>
     */
    private static function slots(Shape $shape, bool $ignored, float $min, float $max): array
    {
        return match (true) {
            ShapeKind::Line === $shape->kind => [],
            !$ignored => [[$min, $max]],
            ShapeKind::Rectangle === $shape->kind => [[$min, $min + 2], [$max - 2, $max]],
            default => [],
        };
    }

    /**
     * The gaps between the room taken, each 5 shorter at both ends, those no longer than 10 left out.
     *
     * @param list<array{float, float}> $slots
     * @return list<array{float, float}>
     */
    private static function gaps(array $slots): array
    {
        $slots = \array_values(\array_filter($slots, static fn (array $slot): bool => $slot[0] < $slot[1]));
        \usort($slots, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $gaps = [];
        $reach = $slots[0][1] ?? 0.0;
        foreach ($slots as [$start, $end]) {
            if (($start - $reach) > (2 * self::MARGIN)) {
                $gaps[] = [$reach + self::MARGIN, $start - self::MARGIN];
            }
            $reach = \max($reach, $end);
        }

        return $gaps;
    }
}
