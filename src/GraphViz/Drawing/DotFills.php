<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A colour list's fills as `dot` draws them: `striped`, side by side across a box from its
 * left; `wedged`, as slices of an ellipse from its right, turning up; each as wide as its
 * share of the list ({@see DotStrokes::shares()}) and edged with a thin line.
 */
final class DotFills
{
    /** The width of the line edging each stripe or slice, in points. */
    private const float EDGE = 0.5;
    /** How many steps a whole turn of an ellipse is drawn in. */
    private const int STEPS = 72;

    /**
     * The fill a node's or a cluster's style asks for, if a pattern: stripes across a box,
     * wedges of an ellipse; none for any other style or shape.
     *
     * @param array<string, true> $styles
     * @param array{float, float, float, float} $box left, top, right, bottom
     * @return list<Mark>
     */
    public static function pattern(array $styles, string $shape, array $box, string $colors, string $edge): array
    {
        $shape = \strtolower($shape);
        [$left, $top, $right, $bottom] = $box;

        return match (true) {
            \array_key_exists('striped', $styles) && \in_array($shape, ['box', 'rect', 'rectangle', 'square'], strict: true) => self::stripes($box, $colors, $edge),
            \array_key_exists('wedged', $styles) && \in_array($shape, ['ellipse', 'oval', 'circle'], strict: true) => self::wedges(
                ($left + $right) / 2,
                ($top + $bottom) / 2,
                ($right - $left) / 2,
                ($bottom - $top) / 2,
                $colors,
                $edge,
            ),
            default => [],
        };
    }

    /**
     * @param array{float, float, float, float} $box left, top, right, bottom
     * @return list<Mark>
     */
    private static function stripes(array $box, string $colors, string $edge): array
    {
        [$left, $top, $right, $bottom] = $box;
        $width = $right - $left;
        $marks = [];
        foreach (DotStrokes::shares($colors) as [$fill, $from, $to]) {
            [$start, $end] = [$left + ($from * $width), $left + ($to * $width)];
            $marks[] = new Mark(MarkKind::Polygon, [[$start, $top], [$end, $top], [$end, $bottom], [$start, $bottom]], stroke: $edge, fill: $fill, strokeWidth: self::EDGE);
        }

        return $marks;
    }

    /** @return list<Mark> */
    private static function wedges(float $x, float $y, float $radiusX, float $radiusY, string $colors, string $edge): array
    {
        $marks = [];
        foreach (DotStrokes::shares($colors) as [$fill, $from, $to]) {
            $points = [[$x, $y]];
            $steps = \max(1, (int) \ceil(($to - $from) * self::STEPS));
            for ($step = 0; $step <= $steps; ++$step) {
                $angle = 2 * \M_PI * ($from + ((($to - $from) * $step) / $steps));
                // Turning up the screen, its y running down.
                $points[] = [$x + ($radiusX * \cos($angle)), $y - ($radiusY * \sin($angle))];
            }
            $marks[] = new Mark(MarkKind::Polygon, $points, stroke: $edge, fill: $fill, strokeWidth: self::EDGE);
        }

        return $marks;
    }
}
