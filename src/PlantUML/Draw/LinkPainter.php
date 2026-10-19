<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A link drawn as PlantUML draws it, in points: its route smoothed and trimmed at the boxes
 * it joins, the mark at each end with the line stopping short to meet it, its line dashed,
 * dotted, bold or coloured as written, its label where the layout put it — after a small
 * triangle when written `label >` or `< label` — and the labels at its ends beside the line.
 */
final class LinkPainter
{
    /** @return list<Mark> */
    public static function marks(PlantUmlLink $link, Placement $placement, int $edge, PlantUmlText $label, Style $style, string $background): array
    {
        if ($link->hidden) {
            return [];
        }
        $from = $placement->endpoints[$link->from];
        $to = $placement->endpoints[$link->to];
        $route = $placement->routes[$edge] ?? [[$from->x, $from->y], [$to->x, $to->y]];
        $points = BoxClip::clipped(Route::smooth($route), $from, $to);
        $color = ClassColors::back($link->color) ?? $style->color('LineColor', '#181818');
        $stroke = $link->thickness ?? ($link->bold ? 2.0 : $style->number('LineThickness', 1.0));
        $marks = [];
        foreach ([[$link->toDecor, false], [$link->fromDecor, true]] as [$decor, $reversed]) {
            $points = $reversed ? \array_reverse($points) : $points;
            [$tip, $along] = Route::end($points);
            [$ends, $short] = Extremity::marks($decor, $tip, $along, $color, 'none' === $background ? '#ffffff' : $background, $stroke);
            \array_push($marks, ...$ends);
            $points = $short > 0 ? Route::shorten($points, $short)[0] : $points;
            $points = $reversed ? \array_reverse($points) : $points;
        }
        $marks[] = new Mark(MarkKind::Polyline, $points, stroke: $color, dashed: $link->dashed || $link->dotted, strokeWidth: $stroke, dashes: self::dashes($link));
        if (!$label->empty()) {
            [$width, $height] = $label->size();
            [$x, $y] = $placement->labels[$edge] ?? $points[\intdiv(\count($points), num2: 2)];
            \array_push($marks, ...$label->marks($x - ($width / 2), $y - ($height / 2), $width));
            \array_push($marks, ...self::labelArrow($link, $from, $to, $x - ($width / 2) - 8, $y, $color));
        }
        foreach ([[$link->firstLabel, $points], [$link->secondLabel, \array_reverse($points)]] as [$text, $path]) {
            \array_push($marks, ...self::endLabel($text, $path, $style->fontSize()));
        }

        return $marks;
    }

    /** @return list<Mark> the small triangle before a label written `label >` or `< label`, pointing the way the label reads */
    private static function labelArrow(PlantUmlLink $link, GraphNode $from, GraphNode $to, float $x, float $y, string $color): array
    {
        if (null === $link->labelArrow) {
            return [];
        }
        [$dx, $dy] = '>' === $link->labelArrow ? [$to->x - $from->x, $to->y - $from->y] : [$from->x - $to->x, $from->y - $to->y];
        [$dx, $dy] = \abs($dx) > \abs($dy) ? [$dx <=> 0, 0] : [0, $dy <=> 0];

        return [new Mark(
            MarkKind::Polygon,
            [[$x + (4 * $dx), $y + (4 * $dy)], [$x - (4 * $dx) - (3 * $dy), $y - (4 * $dy) + (3 * $dx)], [$x - (4 * $dx) + (3 * $dy), $y - (4 * $dy) - (3 * $dx)]],
            stroke: $color,
            fill: $color,
            strokeWidth: 0.5,
        )];
    }

    /**
     * A label at a link's end, set beside the line a little way from the entity.
     *
     * @param list<array{float, float}> $path from that end
     * @return list<Mark>
     */
    private static function endLabel(?string $text, array $path, float $size): array
    {
        if (null === $text || \count($path) < 2) {
            return [];
        }
        $label = new PlantUmlText(ClassReader::split($text), $size);
        [$width, $height] = $label->size();
        [$x0, $y0] = $path[0];
        [$x1, $y1] = $path[1];
        $length = \max(1e-9, \hypot($x1 - $x0, $y1 - $y0));
        [$dx, $dy] = [($x1 - $x0) / $length, ($y1 - $y0) / $length];
        $x = $x0 + ($dx * 12) + ($dy * (($width / 2) + 4));
        $y = $y0 + ($dy * 12) - ($dx * (($height / 2) + 2));

        return $label->marks($x - ($width / 2), $y - ($height / 2), $width);
    }

    /** @return ?list<float> */
    private static function dashes(PlantUmlLink $link): ?array
    {
        return match (true) {
            $link->dotted => [1.0, 3.0],
            $link->dashed => [7.0, 7.0],
            default => null,
        };
    }
}
