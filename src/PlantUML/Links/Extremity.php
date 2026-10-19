<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Links;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * The mark at one end of a link, as PlantUML's extremities draw it, in points: arrows,
 * triangles, diamonds, circles, crow's feet and the rest, drawn back from the end along the
 * link, with how far the line stops short of the end to meet the mark.
 */
final readonly class Extremity
{
    /**
     * @param array{float, float} $tip where the link ends
     * @param array{float, float} $along the unit direction it arrives in
     */
    private function __construct(
        private array $tip,
        private array $along,
        private string $color,
        private string $background,
        private float $stroke,
    ) {}

    /**
     * @param array{float, float} $tip
     * @param array{float, float} $along
     * @return array{list<Mark>, float} the marks, and how far before the tip the line stops
     */
    public static function marks(LinkDecor $decor, array $tip, array $along, string $color, string $background, float $stroke): array
    {
        $end = new self($tip, $along, $color, $background, $stroke);

        return match ($decor) {
            LinkDecor::None => [[], 0.0],
            LinkDecor::Arrow => [[$end->filled([[0.0, 0.0], [9.0, -4.0], [5.0, 0.0], [9.0, 4.0]])], 5.0],
            LinkDecor::ArrowTriangle => [[$end->filled([[0.0, 0.0], [10.0, -5.0], [10.0, 5.0]])], 10.0],
            LinkDecor::Extends, LinkDecor::Redefines, LinkDecor::DefinedBy => [[$end->polygon([[0.0, 0.0], [19.0, -7.0], [19.0, 7.0]])], 19.0],
            LinkDecor::Composition => [[$end->filled([[0.0, 0.0], [6.0, -4.0], [12.0, 0.0], [6.0, 4.0]])], 12.0],
            LinkDecor::Aggregation => [[$end->polygon([[0.0, 0.0], [6.0, -4.0], [12.0, 0.0], [6.0, 4.0]])], 12.0],
            LinkDecor::NotNavigable => [[$end->line(1.0, -4.0, 9.0, 4.0), $end->line(1.0, 4.0, 9.0, -4.0)], 0.0],
            LinkDecor::Circle => [[$end->polygon($end->round(6.0, 6.0))], 12.0],
            LinkDecor::CircleFill => [[$end->filled($end->round(6.0, 6.0))], 12.0],
            LinkDecor::Plus => [[$end->polygon($end->round(6.0, 6.0)), $end->line(6.0, -6.0, 6.0, 6.0), $end->line(0.0, 0.0, 12.0, 0.0)], 12.0],
            LinkDecor::Square => [[$end->polygon([[0.0, -5.0], [10.0, -5.0], [10.0, 5.0], [0.0, 5.0]])], 10.0],
            LinkDecor::Crowfoot => [$end->crowfoot(), 0.0],
            LinkDecor::LineCrowfoot => [[...$end->crowfoot(), $end->line(12.0, -8.0, 12.0, 8.0)], 0.0],
            LinkDecor::CircleCrowfoot => [[...$end->crowfoot(), $end->polygon($end->round(10.0, 4.0))], 0.0],
            LinkDecor::DoubleLine => [[$end->line(4.0, -8.0, 4.0, 8.0), $end->line(8.0, -8.0, 8.0, 8.0)], 0.0],
            LinkDecor::CircleLine => [[$end->line(4.0, -8.0, 4.0, 8.0), $end->polygon($end->round(8.0, 4.0))], 0.0],
            LinkDecor::HalfArrowUp => [[$end->line(0.0, 0.0, 9.0, -4.0)], 0.0],
            LinkDecor::HalfArrowDown => [[$end->line(0.0, 0.0, 9.0, 4.0)], 0.0],
            LinkDecor::Parenthesis, LinkDecor::CircleConnect => [[$end->arc()], 2.0],
        };
    }

    /** @return array{float, float} a point given back from the tip along the link, and across it */
    private function at(float $back, float $across): array
    {
        [$dx, $dy] = $this->along;

        return [$this->tip[0] - ($dx * $back) - ($dy * $across), $this->tip[1] - ($dy * $back) + ($dx * $across)];
    }

    /** @param list<array{float, float}> $points back and across */
    private function polygon(array $points): Mark
    {
        return new Mark(MarkKind::Polygon, $this->placed($points), stroke: $this->color, fill: $this->background, strokeWidth: $this->stroke);
    }

    /** @param list<array{float, float}> $points back and across */
    private function filled(array $points): Mark
    {
        return new Mark(MarkKind::Polygon, $this->placed($points), stroke: $this->color, fill: $this->color, strokeWidth: $this->stroke);
    }

    /**
     * @param list<array{float, float}> $points back and across
     * @return list<array{float, float}>
     */
    private function placed(array $points): array
    {
        $placed = [];
        foreach ($points as [$back, $across]) {
            $placed[] = $this->at($back, $across);
        }

        return $placed;
    }

    private function line(float $fromBack, float $fromAcross, float $toBack, float $toAcross): Mark
    {
        return new Mark(MarkKind::Polyline, [$this->at($fromBack, $fromAcross), $this->at($toBack, $toAcross)], stroke: $this->color, strokeWidth: $this->stroke);
    }

    /** @return list<array{float, float}> a circle's outline, back and across, its centre on the link */
    private function round(float $centre, float $radius): array
    {
        $points = [];
        for ($step = 0; $step < 20; ++$step) {
            $points[] = [$centre + ($radius * \cos(($step * \M_PI) / 10)), $radius * \sin(($step * \M_PI) / 10)];
        }

        return $points;
    }

    /** @return list<Mark> */
    private function crowfoot(): array
    {
        return [$this->line(8.0, 0.0, 0.0, -8.0), $this->line(8.0, 0.0, 0.0, 0.0), $this->line(8.0, 0.0, 0.0, 8.0)];
    }

    private function arc(): Mark
    {
        $points = [];
        for ($step = -6; $step <= 6; ++$step) {
            $points[] = $this->at(10.0 - (8.0 * \cos(($step * \M_PI) / 12)), 8.0 * \sin(($step * \M_PI) / 12));
        }

        return new Mark(MarkKind::Polyline, $points, stroke: $this->color, strokeWidth: $this->stroke);
    }
}
