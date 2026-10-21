<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A whole rule as PlantUML's `ETileWithCircles` draws it: its railroad 30 in from either
 * side, in lines 1.5 thick, from a ring 8 across to a disc.
 */
final class CirclesTile extends EbnfTile
{
    private const float SIZE = 8.0;
    private const float DELTA_X = 30.0;

    public function __construct(
        private readonly EbnfTile $orig,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + (2 * self::DELTA_X);
    }

    #[\Override]
    public function h1(): float
    {
        return $this->orig->h1();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->orig->h2();
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        $pen = $pen->with(1.5);
        $line = $y + $this->h1();
        $right = $x + $this->width();
        $radius = self::SIZE / 2;

        return [
            ...$this->orig->marks($x + self::DELTA_X, $y, $pen),
            self::circle($x + $radius, $line, $radius, $pen->color, 'none', 2.0),
            self::circle($right, $line, $radius, $pen->color, $pen->color, 1.0),
            ...$pen->directed($line, $x + self::SIZE, $x + self::DELTA_X, 0.5, 25.0),
            ...$pen->directed($line, $right - self::DELTA_X, $right - $radius, 0.5, 25.0),
        ];
    }

    private static function circle(float $x, float $y, float $radius, string $line, string $fill, float $width): Mark
    {
        $points = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $points[] = [$x + ($radius * \cos($angle)), $y + ($radius * \sin($angle))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: $line, fill: $fill, strokeWidth: $width);
    }
}
