<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Links;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;

/**
 * The mark at one end of a link, as PlantUML's extremities draw it, in points: arrows,
 * triangles, diamonds, circles, crow's feet and the rest, drawn back from the end along the
 * link, with how far the line stops short of the end to meet the mark; each taken, when the
 * drawing is measured, as PlantUML's limit finder takes the shape PlantUML draws it as.
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
            LinkDecor::Arrow => [[$end->filled([[0.0, 0.0], [9.0, -4.0], [5.0, 0.0], [9.0, 4.0]], Outline::Polygon)], 5.0],
            LinkDecor::ArrowTriangle => [[$end->filled([[0.0, 0.0], [8.0, -3.0], [8.0, 3.0]], Outline::Polygon)], 8.0],
            LinkDecor::Extends => [[$end->polygon([[0.0, 0.0], [18.0, -6.0], [18.0, 6.0]], Outline::Polygon, 'none')], 18.0],
            LinkDecor::Redefines => [[$end->polygon([[0.0, 0.0], [19.0, -7.0], [19.0, 7.0]], Outline::Polygon), $end->line(22.8, -7.0, 22.8, 7.0, 2.0)], 18.0],
            LinkDecor::DefinedBy => [
                [
                    $end->polygon([[0.0, 0.0], [19.0, -7.0], [19.0, 7.0]], Outline::Polygon),
                    $end->filled($end->round(24.7, 2.0, -5.0), Outline::Ellipse),
                    $end->filled($end->round(24.7, 2.0, 5.0), Outline::Ellipse),
                ],
                18.0,
            ],
            LinkDecor::Composition => [[$end->filled([[0.0, 0.0], [6.0, -4.0], [12.0, 0.0], [6.0, 4.0]], Outline::Polygon)], 12.0],
            LinkDecor::Aggregation => [[$end->polygon([[0.0, 0.0], [6.0, -4.0], [12.0, 0.0], [6.0, 4.0]], Outline::Polygon, 'none')], 12.0],
            LinkDecor::NotNavigable => [[$end->line(5.0, -4.0, 13.0, 4.0), $end->line(5.0, 4.0, 13.0, -4.0)], 8.0],
            LinkDecor::Circle => [[$end->polygon($end->round(6.0, 6.0), Outline::Ellipse, stroke: 1.5)], 12.0],
            LinkDecor::CircleFill => [[$end->filled($end->round(6.0, 6.0), Outline::Ellipse, 1.5)], 12.0],
            LinkDecor::Plus => [[$end->polygon($end->round(8.0, 8.0), Outline::Ellipse), $end->line(8.0, -8.0, 8.0, 8.0), $end->line(0.0, 0.0, 16.0, 0.0)], 16.0],
            LinkDecor::Square => [[$end->polygon([[-5.0, -5.0], [5.0, -5.0], [5.0, 5.0], [-5.0, 5.0]], Outline::Box, stroke: 1.5)], 5.0],
            LinkDecor::Crowfoot => [$end->crowfoot(8.0), 8.0],
            LinkDecor::LineCrowfoot => [[...$end->crowfoot(6.0), $end->line(10.0, -4.0, 10.0, 4.0)], 8.0],
            LinkDecor::CircleCrowfoot => [[...$end->crowfoot(6.0), $end->polygon($end->round(14.0, 4.0), Outline::Ellipse, 'none')], 18.0],
            LinkDecor::DoubleLine => [[$end->line(4.0, -4.0, 4.0, 4.0), $end->line(7.0, -4.0, 7.0, 4.0), $end->line(8.0, 0.0, 0.0, 0.0)], 8.0],
            LinkDecor::CircleLine => $end->circleLine(),
            LinkDecor::HalfArrowUp => [[$end->line(0.0, 0.0, 9.0, -4.0), $end->line(0.0, 0.0, 8.0, 0.0)], 8.0],
            LinkDecor::HalfArrowDown => [[$end->line(0.0, 0.0, 9.0, 4.0), $end->line(0.0, 0.0, 8.0, 0.0)], 8.0],
            LinkDecor::Parenthesis => [[$end->arc(9.0, 70.0), $end->taken(9.0)], 10.0],
            LinkDecor::CircleConnect => [[$end->polygon($end->round(0.0, 6.0), Outline::Ellipse, stroke: 1.5), $end->arc(10.0, 45.0), $end->taken(10.0)], 10.0],
        };
    }

    /** @return array{float, float} a point given back from the tip along the link, and across it */
    private function at(float $back, float $across): array
    {
        [$dx, $dy] = $this->along;

        return [$this->tip[0] - ($dx * $back) - ($dy * $across), $this->tip[1] - ($dy * $back) + ($dx * $across)];
    }

    /** @param list<array{float, float}> $points back and across */
    private function polygon(array $points, Outline $outline, ?string $fill = null, ?float $stroke = null): Mark
    {
        return new Mark(MarkKind::Polygon, $this->placed($points), stroke: $this->color, fill: $fill ?? $this->background, strokeWidth: $stroke ?? $this->stroke, outline: $outline);
    }

    /** @param list<array{float, float}> $points back and across */
    private function filled(array $points, Outline $outline, ?float $stroke = null): Mark
    {
        return $this->polygon($points, $outline, $this->color, $stroke);
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

    private function line(float $fromBack, float $fromAcross, float $toBack, float $toAcross, ?float $stroke = null): Mark
    {
        return new Mark(MarkKind::Polyline, [$this->at($fromBack, $fromAcross), $this->at($toBack, $toAcross)], stroke: $this->color, strokeWidth: $stroke ?? $this->stroke);
    }

    /** @return list<array{float, float}> a circle's outline, back and across, its centre on the link or across from it */
    private function round(float $centre, float $radius, float $across = 0.0): array
    {
        $points = [];
        for ($step = 0; $step < 20; ++$step) {
            $points[] = [$centre + ($radius * \cos(($step * \M_PI) / 10)), $across + ($radius * \sin(($step * \M_PI) / 10))];
        }

        return $points;
    }

    /** @return list<Mark> three lines spread from a point 8 back to the tip */
    private function crowfoot(float $aperture): array
    {
        return [$this->line(8.0, 0.0, 0.0, -$aperture), $this->line(8.0, 0.0, 0.0, $aperture), $this->line(8.0, 0.0, 0.0, 0.0)];
    }

    /**
     * A circle and a bar, both as big as the line is thick and 3 more.
     *
     * @return array{list<Mark>, float}
     */
    private function circleLine(): array
    {
        $radius = 3 + $this->stroke;
        $centre = 7 + $radius;

        return [[$this->line($centre, 0.0, 0.0, 0.0), $this->polygon($this->round($centre, $radius), Outline::Ellipse, 'none'), $this->line(4.0, -$radius, 4.0, $radius)], 15.0];
    }

    /** An arc about the tip, as far either side of the link's line as given in degrees, 1.5 thick. */
    private function arc(float $radius, float $degrees): Mark
    {
        $points = [];
        for ($step = -6; $step <= 6; ++$step) {
            $angle = \deg2rad(($degrees * $step) / 6);
            $points[] = $this->at($radius * \cos($angle), $radius * \sin($angle));
        }

        return new Mark(MarkKind::Polyline, $points, stroke: $this->color, strokeWidth: 1.5);
    }

    /** The whole circle about the tip an arc is drawn on, unseen, as PlantUML's limit finder takes the arc. */
    private function taken(float $radius): Mark
    {
        return new Mark(MarkKind::Polygon, $this->placed($this->round(0.0, $radius)), stroke: 'none', fill: 'none', strokeWidth: 0.0, outline: Outline::Ellipse);
    }
}
