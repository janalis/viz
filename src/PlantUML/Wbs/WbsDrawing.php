<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/**
 * A work breakdown structure drawn as PlantUML's `WBSDiagram` and `Fork` draw it: the root's
 * children side by side, 20 apart, 40 under its box, each hanging from a line across 20 under
 * it that a line down from the root's middle meets — the root over the middle of the line
 * across, or of the whole when it has a single child; 10 clear all round, then the arrows
 * between elements, from the edge of one's box to the edge of the other's.
 */
final class WbsDrawing
{
    private const float INSET = 10.0;
    private const float GAP = 20.0;
    private const float DROP = 40.0;

    public static function draw(PlantUmlWbs $diagram): Drawing
    {
        $root = new WbsBlock($diagram->root, $diagram->document->styles);
        $root->autoWidth();
        $geometry = [];
        [$width, $height] = self::size($root);
        $marks = self::fork($root, self::INSET, self::INSET, $width, $geometry);
        foreach ($diagram->links as [$from, $to, $color, $stereotype]) {
            \array_push($marks, ...self::link($geometry[\spl_object_id($from)] ?? null, $geometry[\spl_object_id($to)] ?? null, self::linkColor($diagram, $color, $stereotype)));
        }

        return PlantUmlFrame::drawing($diagram->document, $marks, $width + (2 * self::INSET), $height + (2 * self::INSET));
    }

    /** @return array{float, float} as PlantUML's `Fork` measures it */
    private static function size(WbsBlock $root): array
    {
        [$mainWidth, $mainHeight] = $root->main();
        $width = 0.0;
        $height = 0.0;
        foreach ($root->right as $child) {
            [$childWidth, $childHeight] = $child->size();
            $width += $childWidth;
            $height = \max($height, $childHeight);
        }
        $width += \max(0, \count($root->right) - 1) * self::GAP;

        return [\max($width, $mainWidth), $height + $mainHeight + self::DROP];
    }

    /**
     * @param array<int, array{float, float, float, float}> $geometry
     * @return list<Mark> as PlantUML's `Fork` draws it, its top left at a point
     */
    private static function fork(WbsBlock $root, float $x, float $y, float $width, array &$geometry): array
    {
        [$mainWidth, $mainHeight] = $root->main();
        $y0 = $y + $mainHeight;
        $y1 = $y0 + (self::DROP / 2);
        $y2 = $y0 + self::DROP;
        if ([] === $root->right) {
            return [...$root->mainMarks($x, $y), ...$root->line($x + ($mainWidth / 2), $y0, $x + ($mainWidth / 2), $y1)];
        }
        $marks = [];
        $left = $x;
        $first = $x + $root->right[0]->middle();
        $last = $first;
        foreach ($root->right as $child) {
            $last = $left + $child->middle();
            \array_push($marks, ...$root->line($last, $y1, $last, $y2), ...$child->marks($left, $y2, $geometry));
            $left += $child->size()[0] + self::GAP;
        }
        // Over the middle of the line across, or of the whole under a single child, the line across reaching it.
        $main = $last > $first ? $first + (($last - $first - $mainWidth) / 2) : $x + (($width - $mainWidth) / 2);
        \array_push($marks, ...$root->line($first, $y1, $last > $first ? $last : $main + ($mainWidth / 2), $y1));

        return [...$marks, ...$root->mainMarks($main, $y), ...$root->line($main + ($mainWidth / 2), $y0, $main + ($mainWidth / 2), $y1)];
    }

    /** The colour an arrow is given, `#colour` or `#line:colour`, or else its style's. */
    private static function linkColor(PlantUmlWbs $diagram, ?string $color, ?string $stereotype): string
    {
        $given = match (true) {
            null === $color => null,
            \str_contains($color, ':') || \str_contains($color, ';') => ClassColors::line($color),
            default => PlantUmlColor::parse($color),
        };
        $stereotypes = null === $stereotype ? [] : ClassReader::stereotypes($stereotype);

        return $given ?? $diagram->document->styles->style(['root', 'element', 'wbsDiagram', 'arrow'], $stereotypes)->color('LineColor', '#181818');
    }

    /**
     * As PlantUML's `WBSLink`: a line between the middles of two elements' boxes, cut at their
     * edges, an arrowhead where it reaches the second; none when either is undrawn.
     *
     * @param ?array{float, float, float, float} $from
     * @param ?array{float, float, float, float} $to
     * @return list<Mark>
     */
    private static function link(?array $from, ?array $to, string $color): array
    {
        if (null === $from || null === $to || 'none' === $color) {
            return [];
        }
        $line = [$from[0] + ($from[2] / 2), $from[1] + ($from[3] / 2), $to[0] + ($to[2] / 2), $to[1] + ($to[3] / 2)];
        $start = self::edge($from, $line);
        $end = self::edge($to, $line);
        if (null === $start || null === $end) {
            return [];
        }
        $angle = self::round(\atan2($end[1] - $start[1], $end[0] - $start[0]));
        [$cos, $sin] = [\cos($angle), \sin($angle)];
        $head = [];
        foreach ([[0.0, 0.0], [-9.0, -4.0], [-5.0, 0.0], [-9.0, 4.0], [0.0, 0.0]] as [$dx, $dy]) {
            $head[] = [$end[0] + ($dx * $cos) - ($dy * $sin), $end[1] + ($dx * $sin) + ($dy * $cos)];
        }

        return [
            new Mark(MarkKind::Polyline, [$start, $end], stroke: $color, strokeWidth: 1.0),
            new Mark(MarkKind::Polygon, $head, stroke: $color, fill: $color, strokeWidth: 1.0),
        ];
    }

    /**
     * Where a line crosses a box's sides, top, right, bottom then left, as PlantUML's `XRectangle2D::intersect`.
     *
     * @param array{float, float, float, float} $box
     * @param array{float, float, float, float} $line
     * @return ?array{float, float}
     */
    private static function edge(array $box, array $line): ?array
    {
        [$x, $y, $width, $height] = $box;
        $corners = [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]];
        [$x1, $y1, $x2, $y2] = $line;
        [$s1x, $s1y] = [$x2 - $x1, $y2 - $y1];
        foreach ($corners as $index => [$x3, $y3]) {
            [$x4, $y4] = $corners[($index + 1) % 4];
            [$s2x, $s2y] = [$x4 - $x3, $y4 - $y3];
            $denominator = (-$s2x * $s1y) + ($s1x * $s2y);
            if (0.0 === $denominator) {
                continue;
            }
            $s = ((-$s1y * ($x1 - $x3)) + ($s1x * ($y1 - $y3))) / $denominator;
            $t = (($s2x * ($y1 - $y3)) - ($s2y * ($x1 - $x3))) / $denominator;
            if ($s >= 0 && $s <= 1 && $t >= 0 && $t <= 1) {
                return [$x1 + ($t * $s1x), $y1 + ($t * $s1y)];
            }
        }

        return null;
    }

    /** An angle within 0.05° of a right angle made one, as PlantUML's `Extremity::manageround`. */
    private static function round(float $angle): float
    {
        $degrees = \rad2deg($angle);
        foreach ([0, 90, 180, 270, 360] as $right) {
            if (\abs($right - $degrees) < 0.05) {
                return \deg2rad($right % 360);
            }
        }

        return $angle;
    }
}
