<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;

/** How a filled arrowhead is drawn: notched, as PlantUML draws one, or a plain triangle, as its strict UML style does. */
enum ArrowHead
{
    case Notched;
    case Triangle;

    public static function of(PlantUmlDocument $document): self
    {
        return $document->strictUml() ? self::Triangle : self::Notched;
    }

    /**
     * A filled head's outline: its barbs, its tip, and its notch, a point 6 tenths of the way back
     * from its tip to its barbs' middle, but a plain triangle's.
     *
     * @param list<array{float, float}> $points barb, tip, barb, notch
     * @return list<array{float, float}>
     */
    public function outline(array $points): array
    {
        return self::Triangle === $this ? \array_slice($points, offset: 0, length: 3) : $points;
    }

    /**
     * Marks with every notched head among them made a plain triangle, for a strict UML style.
     *
     * @param list<Mark> $marks
     * @return list<Mark>
     */
    public function marks(array $marks): array
    {
        return self::Triangle === $this
            ? \array_map(static fn (Mark $mark): Mark => self::notched($mark) ? $mark->with(['points' => \array_slice($mark->points, offset: 0, length: 3)]) : $mark, $marks) : $marks;
    }

    /** Whether a mark is a filled head with its notch: four points, the last 6 tenths of the way from the second to the middle of the first and third. */
    private static function notched(Mark $mark): bool
    {
        if (MarkKind::Polygon !== $mark->kind || 4 !== \count($mark->points)) {
            return false;
        }
        [$barb, $tip, $other, $notch] = $mark->points;
        [$x, $y] = [$tip[0] + (0.6 * ((($barb[0] + $other[0]) / 2) - $tip[0])), $tip[1] + (0.6 * ((($barb[1] + $other[1]) / 2) - $tip[1]))];

        return \abs($notch[0] - $x) < 1e-6 && \abs($notch[1] - $y) < 1e-6;
    }
}
