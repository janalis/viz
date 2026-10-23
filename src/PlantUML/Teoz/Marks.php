<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;

/** Marks moved as a whole, and how far down they reach. */
final class Marks
{
    /**
     * @param list<Mark> $marks
     * @return list<Mark>
     */
    public static function moved(array $marks, float $dx, float $dy): array
    {
        return \array_map(static fn (Mark $mark): Mark => Units::moved($mark, $dx, $dy), $marks);
    }

    /**
     * The lowest point of the shapes among marks, text aside, or 0.
     *
     * @param list<Mark> $marks
     */
    public static function bottom(array $marks): float
    {
        $bottom = 0.0;
        foreach ($marks as $mark) {
            if (MarkKind::Text === $mark->kind) {
                continue;
            }
            foreach ($mark->points as [, $y]) {
                $bottom = \max($bottom, $y);
            }
        }

        return $bottom;
    }
}
