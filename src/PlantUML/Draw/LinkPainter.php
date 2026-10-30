<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
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
        $whole = \array_key_exists($edge, $placement->controls) ? [$controls[0], $controls[\count($controls) - 1]] : $whole;
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
        if ('' !== $link->middle) {
            \array_push($marks, ...self::circled($link->middle, $points, $color, 'none' === $background ? '#ffffff' : $background));
        }
        $labelBox = null;
        if (!$label->empty()) {
            $margin = Svek::labelMargin($link->from, $link->to);
            [$width, $height] = $label->size($margin);
            [$x, $y] = $placement->labels[$edge] ?? $points[\intdiv(\count($points), num2: 2)];
            // As PlantUML, from the corner of the box svek wrote, in whole points.
            [$left, $top] = \array_key_exists($edge, $placement->labels)
                ? [$x - (\floor($width) / 2), $y - (\floor($height) / 2)]
                : [$x - ($width / 2), $y - ($height / 2)];
            \array_push($marks, ...$label->marks($left, $top, $margin));
            $labelBox = [$left, $top, $left + \floor($width), $top + \floor($height)];
            // As svek's `TextBlockArrow2`: its middle 5 in from the block's left, outside the room round the text.
            \array_push($marks, ...self::labelArrow($link, $controls, $left + 5, $y, $color));
        }
        \array_push($marks, ...$boxes);
        $direction = $placement->directions[''] ?? Direction::TopBottom;
        $flat = $link->from !== $link->to && self::flat($from, $to, $direction);
        \array_push($marks, ...self::endLabels(
            $link->firstLabel,
            $link->secondLabel,
            $whole,
            $style->fontSize(),
            $placement->graph->nodes,
            match (true) {
                $link->from === $link->to => self::loopSides($direction),
                $flat => self::flatSides($direction, $whole),
                default => self::sides($direction),
            },
            // On a link along a rank, its label stands in the way too.
            $flat && null !== $labelBox ? [$labelBox] : [],
        ));

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
     * The mark in a link's middle, as PlantUML's `MiddleCircle` and `MiddleCircleCircled` draw
     * it where the line comes nearest midway between its ends: a circle 6 across filled as the
     * background; an arc 10 across of a quarter turn about the line's way there on its head's
     * side, its tail's, or both over a disc of the background.
     *
     * @param list<array{float, float}> $points
     * @return list<Mark>
     */
    private static function circled(string $middle, array $points, string $color, string $background): array
    {
        [[$cx, $cy], $angle] = self::middle($points);
        $marks = '(0)' === $middle ? [DescriptionBox::ellipse($cx - 10, $cy - 10, 20, 20, $background, $background, 1.5)] : [];
        foreach (['0)' => 0.0, '(0' => \M_PI] as $mark => $turn) {
            if ($mark !== $middle && '(0)' !== $middle) {
                continue;
            }
            $arc = [];
            for ($step = 0; $step <= 12; ++$step) {
                $at = $angle + $turn - (\M_PI / 4) + (($step / 12) * (\M_PI / 2));
                $arc[] = [$cx + (10 * \cos($at)), $cy + (10 * \sin($at))];
            }
            $marks[] = new Mark(MarkKind::Polyline, $arc, stroke: $color, strokeWidth: 1.5);
        }

        return [...$marks, DescriptionBox::ellipse($cx - 6, $cy - 6, 12, 12, $color, $background, 1.5)];
    }

    /**
     * The point of a line nearest midway between its ends, as PlantUML's `DotPath` finds its
     * middle: the least sum of its squared distances to them, and the line's way there.
     *
     * @param list<array{float, float}> $points
     * @return array{array{float, float}, float}
     */
    private static function middle(array $points): array
    {
        // The least sum lies nearest the midpoint of its ends.
        [$start, $end] = [$points[0], $points[\count($points) - 1]];
        $mid = [($start[0] + $end[0]) / 2, ($start[1] + $end[1]) / 2];
        [$best, $distance, $way] = [$start, \INF, 0.0];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            [$a, $b] = [$points[$index - 1], $points[$index]];
            [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
            $length = $dx ** 2 + $dy ** 2;
            $t = $length > 0 ? \max(0.0, \min(1.0, ((($mid[0] - $a[0]) * $dx) + (($mid[1] - $a[1]) * $dy)) / $length)) : 0.0;
            $point = [$a[0] + ($t * $dx), $a[1] + ($t * $dy)];
            $away = \hypot($point[0] - $mid[0], $point[1] - $mid[1]);
            [$best, $distance, $way] = $away < $distance ? [$point, $away, \atan2($dy, $dx)] : [$best, $distance, $way];
        }

        return [$best, $way];
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

    /**
     * The small triangle a label written `label >` or `< label` starts with, pointing the way
     * the label reads, as svek's `TextBlockArrow2` points it: along the line from its start to
     * its end, or a self-link's along the curve's start, turned back for `<`; its corners 5 from
     * its middle, a polygon as PlantUML's limit finder takes one.
     *
     * @param list<array{float, float}> $controls the curve dot gives it, cut at its ends
     * @return list<Mark>
     */
    private static function labelArrow(PlantUmlLink $link, array $controls, float $x, float $y, string $color): array
    {
        if (null === $link->labelArrow) {
            return [];
        }
        $turn = '>' === $link->labelArrow ? 0.0 : \M_PI;
        [$start, $end] = [$controls[0], $controls[\count($controls) - 1]];
        $out = [$controls[1][0] - $start[0], $controls[1][1] - $start[1]];
        $out = [0.0, 0.0] === $out ? [$controls[3][0] - $start[0], $controls[3][1] - $start[1]] : $out;
        // As svek, a self-link's angle is its way out's measured from across, which the triangle reads from down.
        $angle = ($link->from === $link->to ? \atan2($out[1], $out[0]) : \atan2($end[0] - $start[0], $end[1] - $start[1])) + $turn;
        [$dx, $dy] = [\sin($angle), \cos($angle)];
        // Its corners 5 from its middle, two turned 4/5 of a half turn from its point.
        [$back, $side] = [5 * \cos(0.8 * \M_PI), 5 * \sin(0.8 * \M_PI)];

        return [new Mark(
            MarkKind::Polygon,
            [[$x + (5 * $dx), $y + (5 * $dy)], [$x + ($back * $dx) - ($side * $dy), $y + ($back * $dy) + ($side * $dx)], [$x + ($back * $dx) + ($side * $dy), $y + ($back * $dy) - ($side * $dx)]],
            stroke: $color,
            fill: $color,
            strokeWidth: 0.5,
            outline: Outline::Polygon,
        )];
    }

    /**
     * The labels at a link's ends, as dot sets a tail and a head label: each with a corner on
     * the end of the line, on the sides it is given, but turned where it would overlap what
     * stands in its way, or the head's the tail's.
     *
     * As svek then, each is moved away from any node it comes within 8 of.
     *
     * @param list<array{float, float}> $points from the tail to the head, two at least
     * @param array<int, GraphNode> $nodes
     * @param array{array{int, int}, array{int, int}, ?array{int, int}} $sides the tail's, the head's, and how they turn where they would overlap, if they do
     * @param list<array{float, float, float, float}> $obstacles what else stands in their way
     * @return list<Mark>
     */
    private static function endLabels(?string $tail, ?string $head, array $points, float $size, array $nodes, array $sides, array $obstacles = []): array
    {
        [$tailSides, $headSides, $flip] = $sides;
        $tailBox = self::placed($tail, $points[0], $tailSides, $flip, $size, $obstacles);
        $headBox = self::placed($head, $points[\count($points) - 1], $headSides, $flip, $size, null === $tailBox ? $obstacles : [...$obstacles, $tailBox[1]]);
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
     * Where `dot` is seen to set head and tail labels, whatever the line's slope: laid out down,
     * the head's above its end and the tail's below its start, both on its left — the side the
     * line turns to 25 degrees off it — the head's on the line's other side where they would
     * meet; turned with the layout, where they stay apart.
     *
     * @return array{array{int, int}, array{int, int}, ?array{int, int}}
     */
    private static function sides(Direction $direction): array
    {
        return match ($direction) {
            Direction::TopBottom => [[-1, 1], [-1, -1], [-1, 1]],
            Direction::BottomTop => [[-1, -1], [-1, 1], [-1, 1]],
            Direction::LeftRight => [[1, 1], [-1, 1], null],
            Direction::RightLeft => [[-1, 1], [1, 1], null],
        };
    }

    /** Whether a link runs along a rank: its ends level across the layout, within the rounding of their sizes. */
    private static function flat(GraphNode $from, GraphNode $to, Direction $direction): bool
    {
        return \in_array($direction, [Direction::TopBottom, Direction::BottomTop], strict: true) ? \abs($from->y - $to->y) < 0.5 : \abs($from->x - $to->x) < 0.5;
    }

    /**
     * Where `dot` is seen to set them on a link along a rank: the tail's reaching on along the
     * line, the head's back along it, both on the side of the line the layout comes from — above
     * it laid out down, below it bottom up, left of it left to right, right of it right to left —
     * each turned to the other side where it would meet what stands in its way.
     *
     * @param list<array{float, float}> $points from the tail to the head
     * @return array{array{int, int}, array{int, int}, array{int, int}}
     */
    private static function flatSides(Direction $direction, array $points): array
    {
        [$start, $end] = [$points[0], $points[\count($points) - 1]];
        $across = \in_array($direction, [Direction::TopBottom, Direction::BottomTop], strict: true);
        $along = ($across ? $end[0] - $start[0] : $end[1] - $start[1]) < 0 ? -1 : 1;
        $side = \in_array($direction, [Direction::TopBottom, Direction::LeftRight], strict: true) ? -1 : 1;

        return $across ? [[$along, $side], [-$along, $side], [1, -1]] : [[$side, $along], [$side, -$along], [-1, 1]];
    }

    /**
     * Where `dot` is seen to set them on a link to itself, which it loops on the node's right, or
     * above it laid out left to right: both on the loop's outer side, reaching back against the
     * layout; the head's reaching the other way along it where they would meet.
     *
     * @return array{array{int, int}, array{int, int}, array{int, int}}
     */
    private static function loopSides(Direction $direction): array
    {
        return match ($direction) {
            Direction::TopBottom => [[1, -1], [1, -1], [1, -1]],
            Direction::BottomTop => [[1, 1], [1, 1], [1, -1]],
            Direction::LeftRight => [[-1, -1], [-1, -1], [-1, 1]],
            Direction::RightLeft => [[1, -1], [1, -1], [-1, 1]],
        };
    }

    /**
     * An end's label where it stands: on the sides it is given, or turned where it would meet
     * what stands in its way.
     *
     * @param array{float, float} $end
     * @param array{int, int} $sides
     * @param ?array{int, int} $flip
     * @param list<array{float, float, float, float}> $obstacles
     * @return ?array{PlantUmlText, array{float, float, float, float}}
     */
    private static function placed(?string $text, array $end, array $sides, ?array $flip, float $size, array $obstacles): ?array
    {
        $box = self::endBox($text, $end, $sides, $size);
        $meets = null !== $box && \array_any($obstacles, static fn (array $obstacle): bool => self::overlap($box[1], $obstacle));

        return null !== $flip && $meets ? self::endBox($text, $end, [$flip[0] * $sides[0], $flip[1] * $sides[1]], $size) : $box;
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
        // As svek writes it to dot: a table its size in whole points, cut down, from whose corner PlantUML sets the text.
        $x = $sides[0] < 0 ? $end[0] - \floor($width) : $end[0];
        $y = $sides[1] < 0 ? $end[1] - \floor($height) : $end[1];

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
