<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Teoz\Rose\Bezier;

/**
 * The lines of a railroad as PlantUML's `ETile` draws them, in a colour and a thickness: a
 * line across with a small arrow along it, PlantUML's `CornerCurved` quarter turns and its
 * `Zigzag` swerves.
 */
final readonly class EbnfPen
{
    public function __construct(
        public string $color,
        public float $width,
    ) {}

    public function with(float $width): self
    {
        return new self($this->color, $width);
    }

    /** @return list<Mark> */
    public function line(float $x1, float $y1, float $x2, float $y2): array
    {
        return [new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $this->color, strokeWidth: $this->width)];
    }

    /**
     * A line across with an arrow pointing along it, a part of the way along, when the line is
     * longer than a least length.
     *
     * @return list<Mark>
     */
    public function directed(float $y, float $x1, float $x2, float $coef, float $minimum): array
    {
        $marks = $this->line($x1, $y, $x2, $y);
        if ($x2 > ($x1 + $minimum)) {
            $marks[] = $this->arrow(($x1 * (1 - $coef)) + ($x2 * $coef) - 2, $y, 1.0);
        }

        return $marks;
    }

    /** @return list<Mark> a line across with an arrow pointing back along it */
    public function antiDirected(float $y, float $x1, float $x2, float $coef): array
    {
        return [...$this->line($x1, $y, $x2, $y), $this->arrow(($x1 * (1 - $coef)) + ($x2 * $coef) - 2, $y, -1.0)];
    }

    /**
     * A quarter turn as PlantUML's `CornerCurved` draws it, from its point: `sw` and `se` coming
     * down into it and turning right or left, `ne` and `nw` turning down or up out of it.
     *
     * @return list<Mark>
     */
    public function corner(string $type, float $delta, float $x, float $y): array
    {
        $a = $delta / 4;
        [$start, $control1, $control2, $end] = match ($type) {
            'sw' => [[0.0, -$delta], [0.0, -$a], [$a, 0.0], [$delta, 0.0]],
            'se' => [[0.0, -$delta], [0.0, -$a], [-$a, 0.0], [-$delta, 0.0]],
            'ne' => [[-$delta, 0.0], [-$a, 0.0], [0.0, $a], [0.0, $delta]],
            default => [[0.0, $delta], [0.0, $a], [$a, 0.0], [$delta, 0.0]],
        };

        return [$this->curve([$x + $start[0], $y + $start[1]], [$x + $control1[0], $y + $control1[1]], [$x + $control2[0], $y + $control2[1]], [$x + $end[0], $y + $end[1]])];
    }

    /**
     * PlantUML's `Zigzag`: a swerve down from its point across a width and a height, eased at
     * either end by a control, or up from its bottom left.
     *
     * @return list<Mark>
     */
    public function zigzagDown(float $x, float $y, float $control, float $width, float $height): array
    {
        return $this->zigzag($x, $y, $control, $width, $height, 1);
    }

    /**
     * The same swerve up, from its bottom left to its top right.
     *
     * @return list<Mark>
     */
    public function zigzagUp(float $x, float $y, float $control, float $width, float $height): array
    {
        return $this->zigzag($x, $y, $control, $width, $height, -1);
    }

    /** @return list<Mark> */
    private function zigzag(float $x, float $y, float $control, float $width, float $height, int $sign): array
    {
        [$xm, $ym] = [$x + ($width / 2), $y + ($height / 2)];
        [$top, $bottom] = 1 === $sign ? [$y, $y + $height] : [$y + $height, $y];

        return [
            $this->curve([$x, $top], [$x + $control, $top], [$xm, $ym - ($sign * $control)], [$xm, $ym]),
            $this->curve([$xm, $ym], [$xm, $ym + ($sign * $control)], [$x + $width - $control, $bottom], [$x + $width, $bottom]),
        ];
    }

    /**
     * @param array{float, float} $start
     * @param array{float, float} $control1
     * @param array{float, float} $control2
     * @param array{float, float} $end
     */
    public function curve(array $start, array $control1, array $control2, array $end): Mark
    {
        return new Mark(MarkKind::Polyline, Bezier::points($start, $control1, $control2, $end), stroke: $this->color, strokeWidth: $this->width);
    }

    /** A small filled arrow 6 long and 6 across, its back at a point, pointing right — or left. */
    private function arrow(float $x, float $y, float $direction): Mark
    {
        return new Mark(MarkKind::Polygon, [[$x, $y], [$x, $y - 3], [$x + (6 * $direction), $y], [$x, $y + 3]], stroke: 'none', fill: $this->color, strokeWidth: 0.0);
    }
}
