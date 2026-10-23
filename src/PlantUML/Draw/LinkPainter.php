<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A link drawn as PlantUML draws it, in points: its route smoothed and trimmed at the boxes
 * it joins, the mark at each end with the line stopping short to meet it, its line dashed,
 * dotted, bold or coloured as written, its label where the layout put it — after a small
 * triangle when written `label >` or `< label` — and the labels at its ends beside the line.
 */
final class LinkPainter
{
    /**
     * @param array<string, string> $shapes `oval` or `diamond` by node name, for a node its line stops at the outline of
     * @return list<Mark>
     */
    public static function marks(PlantUmlLink $link, Placement $placement, int $edge, LinkLabel $label, Style $style, string $background, array $shapes = []): array
    {
        if ($link->hidden) {
            return [];
        }
        $from = $placement->endpoints[$link->from];
        $to = $placement->endpoints[$link->to];
        $route = $placement->routes[$edge] ?? [[$from->x, $from->y], [$to->x, $to->y]];
        $points = BoxClip::clipped(Route::smooth($route), $from, $to, $shapes);
        // As dot sees the line, before the ends make room for their marks: where the end labels stand.
        $whole = $points;
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
            // As PlantUML, from the corner of the box svek wrote, its room around it, in whole points.
            $margin = Svek::labelMargin($link->from, $link->to);
            $top = \array_key_exists($edge, $placement->labels) ? $y - (\floor($height + $margin) / 2) + ($margin / 2) : $y - ($height / 2);
            \array_push($marks, ...$label->marks($x - ($width / 2), $top, $width));
            \array_push($marks, ...self::labelArrow($link, $from, $to, $x - ($width / 2) - 8, $y, $color));
        }
        \array_push($marks, ...self::endLabels($link->firstLabel, $link->secondLabel, $whole, $style->fontSize(), $placement->graph->nodes, $placement->directions[''] ?? Direction::TopBottom));

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
     * The labels at a link's ends, as dot sets a tail and a head label: each with a corner on
     * the end of the line, on its left going from tail to head — the side the line turns to
     * 25 degrees off it — but the head's on the other side where it would overlap the tail's.
     *
     * As svek then, each is moved away from any node it comes within 8 of.
     *
     * @param list<array{float, float}> $points from the tail to the head, two at least
     * @param array<int, GraphNode> $nodes
     * @return list<Mark>
     */
    private static function endLabels(?string $tail, ?string $head, array $points, float $size, array $nodes, Direction $direction): array
    {
        $last = \count($points) - 1;
        // As `dot` is seen to set head and tail labels, whatever the line's slope: laid out down, the head's above its end and the tail's below its start, both on its left; turned with the layout.
        $across = \in_array($direction, [Direction::TopBottom, Direction::BottomTop], strict: true);
        [$tailSides, $headSides] = match ($direction) {
            Direction::TopBottom => [[-1, 1], [-1, -1]],
            Direction::BottomTop => [[-1, -1], [-1, 1]],
            Direction::LeftRight => [[1, 1], [-1, 1]],
            Direction::RightLeft => [[-1, 1], [1, 1]],
        };
        $tailBox = self::endBox($tail, $points[0], $tailSides, $size);
        $headBox = self::endBox($head, $points[$last], $headSides, $size);
        // Laid out down, labels that would meet put the head's on the line's other side, as `dot` sets them.
        if ($across && null !== $tailBox && null !== $headBox && self::overlap($tailBox[1], $headBox[1])) {
            $headBox = self::endBox($head, $points[$last], [-$headSides[0], $headSides[1]], $size);
        }
        $marks = [];
        foreach ([$tailBox, $headBox] as $box) {
            if (null === $box) {
                continue;
            }
            [$label, $at] = $box;
            foreach ($nodes as $node) {
                $at = self::awayFrom([$node->x - ($node->w / 2) - 8, $node->y - ($node->h / 2) - 8, $node->x + ($node->w / 2) + 8, $node->y + ($node->h / 2) + 8], $at);
            }
            [$x0, $y0, $x1] = $at;
            \array_push($marks, ...$label->marks($x0, $y0, $x1 - $x0));
        }

        return $marks;
    }

    /**
     * A label with a corner on a line's end, reaching from it across and down, each by -1 or 1.
     *
     * @param array{float, float} $end
     * @param array{int, int} $sides
     * @return ?array{PlantUmlText, array{float, float, float, float}} the label and its box
     */
    private static function endBox(?string $text, array $end, array $sides, float $size): ?array
    {
        if (null === $text) {
            return null;
        }
        $label = new PlantUmlText(ClassReader::split($text), $size);
        [$width, $height] = $label->size();
        $x = $sides[0] < 0 ? $end[0] - $width : $end[0];
        $y = $sides[1] < 0 ? $end[1] - $height : $end[1];

        return [$label, [$x, $y, $x + $width, $y + $height]];
    }

    /**
     * A box moved straight away from another's centre, as svek moves a label off a node: by
     * the least multiple of the line between their centres, found in a few halvings, that
     * clears it — or left where it is when it does not overlap.
     *
     * @param array{float, float, float, float} $fixed
     * @param array{float, float, float, float} $box
     * @return array{float, float, float, float}
     */
    private static function awayFrom(array $fixed, array $box): array
    {
        if (!self::overlap($fixed, $box)) {
            return $box;
        }
        $dx = (($box[0] + $box[2]) / 2) - (($fixed[0] + $fixed[2]) / 2);
        $dy = (($box[1] + $box[3]) / 2) - (($fixed[1] + $fixed[3]) / 2);
        $moved = static fn (float $c): array => [$box[0] + ($dx * $c), $box[1] + ($dy * $c), $box[2] + ($dx * $c), $box[3] + ($dy * $c)];
        [$min, $max] = [0.0, 0.1];
        while (self::overlap($fixed, $moved($max))) {
            $max *= 2;
        }
        for ($i = 0; $i < 5; ++$i) {
            $candidate = ($min + $max) / 2;
            self::overlap($fixed, $moved($candidate)) ? ($min = $candidate) : ($max = $candidate);
        }

        return $moved(($min + $max) / 2);
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function overlap(array $a, array $b): bool
    {
        return $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
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
