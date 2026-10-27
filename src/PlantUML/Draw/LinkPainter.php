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
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A link drawn as PlantUML draws it, in points: the spline dot fits along its route, or the
 * route smoothed where there is none, trimmed at the boxes it joins, the mark at each end with the line stopping short to meet it, its line dashed,
 * dotted, bold or coloured as written, its label where the layout put it — after a small
 * triangle when written `label >` or `< label` — and the labels at its ends beside the line.
 */
final class LinkPainter
{
    /**
     * @param array<string, string> $shapes `oval` or `diamond` by node name, for a node its line stops at the outline of
     * @param array{?array{PlantUmlText, Style, float}, ?array{PlantUmlText, Style, float}} $qualifiers the qualifier at its first end and at its second, with the style its box is drawn in and how far across it is pushed from its place
     * @param array<string, array{float, float}> $tabs the tab's width and height by node name, for a package or a folder whose tab pushes a link meeting it
     * @return list<Mark>
     */
    public static function marks(
        PlantUmlLink $link,
        Placement $placement,
        int $edge,
        LinkLabel $label,
        Style $style,
        string $background,
        array $shapes = [],
        array $qualifiers = [null, null],
        array $tabs = [],
    ): array {
        if ($link->hidden) {
            return [];
        }
        [$from, $to] = [$placement->endpoints[$link->from], $placement->endpoints[$link->to]];
        $points = self::line($link, $placement, $edge, $shapes);
        // As PlantUML's `Kal` pushed across, its link's start with it.
        $points[0][0] += $qualifiers[0][2] ?? 0.0;
        // As dot sees the line, before the ends make room for their marks: where the end labels stand.
        $whole = $points;
        $color = ClassColors::back($link->color) ?? $style->color('LineColor', '#181818');
        $stroke = $link->thickness ?? ($link->bold ? 2.0 : $style->number('LineThickness', 1.0));
        $controls = CurveClip::clipped($placement->controls[$edge] ?? MagneticPath::straight($points[0], $points[\count($points) - 1]), $from, $to, $shapes);
        $forces = [MagneticPath::force($tabs[$link->from] ?? null, $from, $controls[0]), MagneticPath::force($tabs[$link->to] ?? null, $to, $controls[\count($controls) - 1])];
        // Where dot's curve is known, or a tab pushes an end, the line is drawn as the curve moved; else as it runs, its qualifiers' boxes with it.
        [$points, $marks, $boxes, $extent] =
            [[0.0, 0.0], [0.0, 0.0]] === $forces && ([null, null] !== $qualifiers || !\array_key_exists($edge, $placement->controls))
                ? [...self::ended($link, $points, $qualifiers, $color, $background, $stroke), CurveClip::clipped($placement->controls[$edge] ?? [], $from, $to, $shapes)]
                : MagneticPath::drawn($link, $controls, $forces, $color, 'none' === $background ? '#ffffff' : $background, $stroke);
        // As PlantUML's limit finder takes dot's curve: by its control points too, where they are known.
        $marks[] = new Mark(
            MarkKind::Polyline,
            $points,
            stroke: $color,
            dashed: $link->dashed || $link->dotted,
            strokeWidth: $stroke,
            dashes: self::dashes($link),
            extent: $extent,
        );
        if ($link->middleCircle) {
            // As PlantUML's `MiddleCircle`: 6 across from where the line comes nearest midway between its ends, filled as the background.
            [$cx, $cy] = self::middle($points);
            $marks[] = DescriptionBox::ellipse($cx - 6, $cy - 6, 12, 12, $color, 'none' === $background ? '#ffffff' : $background, 1.5);
        }
        if (!$label->empty()) {
            $margin = Svek::labelMargin($link->from, $link->to);
            [$width, $height] = $label->size($margin);
            [$x, $y] = $placement->labels[$edge] ?? $points[\intdiv(\count($points), num2: 2)];
            // As PlantUML, from the corner of the box svek wrote, in whole points.
            [$left, $top] = \array_key_exists($edge, $placement->labels)
                ? [$x - (\floor($width) / 2), $y - (\floor($height) / 2)]
                : [$x - ($width / 2), $y - ($height / 2)];
            \array_push($marks, ...$label->marks($left, $top, $margin));
            \array_push($marks, ...self::labelArrow($link, $from, $to, $left + ($margin / 2) + 4, $y, $color));
        }
        \array_push($marks, ...$boxes);
        \array_push($marks, ...self::endLabels($link->firstLabel, $link->secondLabel, $whole, $style->fontSize(), $placement->graph->nodes, $placement->directions[''] ?? Direction::TopBottom));

        return $marks;
    }

    /**
     * The line with the mark at each end, the line stopping short to meet it, and the
     * qualifiers' boxes.
     *
     * @param list<array{float, float}> $points
     * @param array{?array{PlantUmlText, Style, float}, ?array{PlantUmlText, Style, float}} $qualifiers
     * @return array{list<array{float, float}>, list<Mark>, list<Mark>} the line, its end marks, the qualifiers' boxes
     */
    private static function ended(PlantUmlLink $link, array $points, array $qualifiers, string $color, string $background, float $stroke): array
    {
        $marks = [];
        $boxes = [];
        foreach ([[$link->toDecor, false, $qualifiers[1]], [$link->fromDecor, true, $qualifiers[0]]] as [$decor, $reversed, $qualifier]) {
            $points = $reversed ? \array_reverse($points) : $points;
            [$tip, $along] = Route::end($points);
            [$box, $delta] = null === $qualifier
                ? [[], [0.0, 0.0]]
                : self::qualifier($qualifier[0], $qualifier[1], [$tip[0] + ($reversed ? 0.0 : $qualifier[2]), $tip[1]], 1 === $link->length, $reversed);
            \array_push($boxes, ...$box);
            // As PlantUML's `Kal`, a qualifier pushes its end's mark past it, and the line's end with it.
            $moved = [$tip[0] + $delta[0], $tip[1] + $delta[1]];
            [$ends, $short] = Extremity::marks($decor, $moved, $along, $color, 'none' === $background ? '#ffffff' : $background, $stroke);
            \array_push($marks, ...$ends);
            if (null !== $qualifier && LinkDecor::None !== $decor) {
                $points = Route::shorten($points, $short + \hypot(...$delta))[0];
                $points[\count($points) - 1] = [$moved[0] - ($along[0] * $short), $moved[1] - ($along[1] * $short)];
            }
            $points = $short > 0 && (null === $qualifier || LinkDecor::None === $decor) ? Route::shorten($points, $short)[0] : $points;
            $points = $reversed ? \array_reverse($points) : $points;
        }

        return [$points, $marks, $boxes];
    }

    /**
     * The point of a line nearest midway between its ends, as PlantUML's `DotPath` finds its
     * middle: the least sum of its squared distances to them.
     *
     * @param list<array{float, float}> $points
     * @return array{float, float}
     */
    private static function middle(array $points): array
    {
        // The least sum lies nearest the midpoint of its ends.
        [$start, $end] = [$points[0], $points[\count($points) - 1]];
        $mid = [($start[0] + $end[0]) / 2, ($start[1] + $end[1]) / 2];
        [$best, $distance] = [$start, \INF];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            [$a, $b] = [$points[$index - 1], $points[$index]];
            [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
            $length = $dx ** 2 + $dy ** 2;
            $t = $length > 0 ? \max(0.0, \min(1.0, ((($mid[0] - $a[0]) * $dx) + (($mid[1] - $a[1]) * $dy)) / $length)) : 0.0;
            $point = [$a[0] + ($t * $dx), $a[1] + ($t * $dy)];
            $away = \hypot($point[0] - $mid[0], $point[1] - $mid[1]);
            [$best, $distance] = $away < $distance ? [$point, $away] : [$best, $distance];
        }

        return $best;
    }

    /**
     * Where a link's line leaves its first node and reaches its second.
     *
     * @param array<string, string> $shapes
     * @return array{array{float, float}, array{float, float}}
     */
    public static function ends(PlantUmlLink $link, Placement $placement, int $edge, array $shapes = []): array
    {
        $points = self::line($link, $placement, $edge, $shapes);

        return [$points[0], $points[\count($points) - 1]];
    }

    /**
     * @param array<string, string> $shapes
     * @return list<array{float, float}>
     */
    private static function line(PlantUmlLink $link, Placement $placement, int $edge, array $shapes): array
    {
        [$from, $to] = [$placement->endpoints[$link->from], $placement->endpoints[$link->to]];
        $route = $placement->routes[$edge] ?? [[$from->x, $from->y], [$to->x, $to->y]];

        return BoxClip::clipped($placement->splines[$edge] ?? Route::smooth($route), $from, $to, $shapes);
    }

    /**
     * A qualifier's box where its end of the line meets its node, as PlantUML's `Kal` draws
     * it: its text 2 in from its sides and 1 from its top and bottom, below the first end and
     * above the second, or for a link laid across, right of the first and left of the second;
     * and how far the end's mark moves past it.
     *
     * @param array{float, float} $end
     * @return array{list<Mark>, array{float, float}}
     */
    private static function qualifier(PlantUmlText $text, Style $style, array $end, bool $across, bool $first): array
    {
        [$width, $height] = $text->size();
        [$width, $height] = [$width + 4, $height + 2];
        [$x, $y] = $end;
        [$left, $top, $delta] = match (true) {
            $across && $first => [$x, $y - ($height / 2), [$width, 0.0]],
            $across => [$x - $width + 0.5, $y - ($height / 2), [-$width, 0.0]],
            $first => [$x - ($width / 2), $y, [0.0, $height]],
            default => [$x - ($width / 2), $y - $height + 0.5, [0.0, -$height]],
        };
        $box = new Mark(
            MarkKind::Polygon,
            [[$left, $top], [$left + $width, $top], [$left + $width, $top + $height], [$left, $top + $height]],
            stroke: $style->color('LineColor', '#181818'),
            fill: $style->color('BackGroundColor', '#f1f1f1'),
            strokeWidth: 0.5,
        );

        return [[$box, ...$text->marks($left + 2, $top + 1, $width - 4)], $delta];
    }

    /** @return list<Mark> the small triangle a label written `label >` or `< label` starts with, pointing the way the label reads: 9 long and 6 wide, as measured on PlantUML's output */
    private static function labelArrow(PlantUmlLink $link, GraphNode $from, GraphNode $to, float $x, float $y, string $color): array
    {
        if (null === $link->labelArrow) {
            return [];
        }
        [$dx, $dy] = '>' === $link->labelArrow ? [$to->x - $from->x, $to->y - $from->y] : [$from->x - $to->x, $from->y - $to->y];
        [$dx, $dy] = \abs($dx) > \abs($dy) ? [$dx <=> 0, 0] : [0, $dy <=> 0];

        return [new Mark(
            MarkKind::Polygon,
            [[$x + (4.52 * $dx), $y + (4.52 * $dy)], [$x - (4.52 * $dx) - (2.94 * $dy), $y - (4.52 * $dy) + (2.94 * $dx)], [$x - (4.52 * $dx) + (2.94 * $dy), $y - (4.52 * $dy) - (2.94 * $dx)]],
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
