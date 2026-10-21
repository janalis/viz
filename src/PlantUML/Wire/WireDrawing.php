<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;

/**
 * A wire diagram drawn as PlantUML's `WireDiagram` draws it, in black: each block a box, its
 * name 5 in at its top in 12-point sans-serif, its printed lines in 10-point; the spots, 5
 * across; the links across — a line with heads of 5, its text on a white ground 4 from the
 * end it leaves, or a bus 10 wide with heads of 15 — then the links down, which write no
 * text. As large as the outer block, and 1 more.
 */
final class WireDrawing
{
    public static function draw(PlantUmlWire $diagram): Drawing
    {
        $marks = self::block($diagram->root);
        foreach ($diagram->spots as $spot) {
            [$x, $y] = $spot->block->absolute($spot->x, $spot->y);
            $marks[] = self::circle($x + 0.5, $y + 0.5, $spot->color);
        }
        foreach ([false, true] as $vertical) {
            foreach ($diagram->links as $link) {
                if ($link->vertical !== $vertical) {
                    continue;
                }
                \array_push($marks, ...$vertical ? self::down($link) : self::across($link));
            }
        }
        [$width, $height] = $diagram->root->size();

        return PlantUmlFrame::drawing($diagram->document, $marks, $width + 1, $height + 1);
    }

    /** @return list<Mark> a block's box, its blocks and printed lines, then its name */
    private static function block(WireBlock $block): array
    {
        [$x, $y] = $block->absolute();
        $marks = [];
        if ('' !== $block->name) {
            [$width, $height] = $block->size();
            $marks[] = ClassBox::rectangle($x, $y, $width, $height, '#000000', $block->color ?? 'none', 1.0, 0.0);
        }
        foreach ($block->children as $child) {
            \array_push($marks, ...self::block($child));
        }
        foreach ($block->prints as [$left, $top, $lines]) {
            \array_push($marks, ...self::text($lines, 10.0, '#000000', $x + $left, $y + $top));
        }
        if ('' !== $block->name) {
            \array_push($marks, ...self::text([\str_replace('_', replace: ' ', subject: $block->name)], 12.0, '#000000', $x + 5, $y));
        }

        return $marks;
    }

    /** @return list<Mark> PlantUML's `WLinkHorizontal` */
    private static function across(WireLink $link): array
    {
        [$x, $y] = $link->start;
        $length = $link->destination - $x;
        $dx = $length - 2;
        $marks = [];
        if ($link->bus) {
            $marks[] = new Mark(
                MarkKind::Polygon,
                \array_map(static fn (array $point): array => [$x + 1 + $point[0], $y + $point[1]], self::bus($link, $dx)),
                stroke: $link->color,
                fill: '#ffffff',
                strokeWidth: 1.0,
            );
        }
        if (!$link->bus) {
            if ($link->forward) {
                $marks[] = self::head([[0, 0], [-5, -5], [-5, 5]], $x + $dx, $y, $link->color);
            }
            if ($link->backward) {
                $marks[] = self::head([[0, 0], [5, -5], [5, 5]], $x + 1, $y, $link->color);
            }
            $marks[] = new Mark(MarkKind::Polyline, [[$x + 1, $y], [$x + 1 + $dx, $y]], stroke: $link->color, strokeWidth: 1.0);
        }
        if (null === $link->label) {
            return $marks;
        }
        $width = \max(\array_map(static fn (string $line): float => new PlantUmlText([$line], 10.0)->size()[0], $link->label));
        $height = \count($link->label) * WireReader::LINE;
        $top = $link->bus ? $y + ((20 - $height) / 2) - 5 : $y - ($height / 2);
        $left = $x
        + match (true) {
            $link->forward && !$link->backward => 4,
            $link->backward && !$link->forward => $length - $width - 4,
            default => ($length - $width) / 2,
        };
        if (!$link->bus) {
            $marks[] = ClassBox::rectangle($left, $top, $width, $height, '#ffffff', '#ffffff', 1.0, 0.0);
        }

        return [...$marks, ...self::text($link->label, 10.0, $link->color, $left, $top)];
    }

    /** @return list<Mark> PlantUML's `WLinkVertical` */
    private static function down(WireLink $link): array
    {
        [$x, $y] = $link->start;
        $dy = $link->destination - $y - 2;
        if ($link->bus) {
            // The bus across, turned a quarter: each point's x and y swapped.
            $points = \array_map(static fn (array $point): array => [$x + $point[1], $y + 1 + $point[0]], self::bus($link, $dy));

            return [new Mark(MarkKind::Polygon, $points, stroke: $link->color, fill: '#ffffff', strokeWidth: 1.0)];
        }
        $marks = [];
        if ($link->forward) {
            $marks[] = self::head([[0, 0], [5, -5], [-5, -5]], $x, $y + $dy, $link->color);
        }
        if ($link->backward) {
            $marks[] = self::head([[0, 0], [5, 5], [-5, 5]], $x, $y + 1, $link->color);
        }
        $marks[] = new Mark(MarkKind::Polyline, [[$x, $y + 1], [$x, $y + 1 + $dy]], stroke: $link->color, strokeWidth: 1.0);

        return $marks;
    }

    /** @return list<array{float, float}> a bus across, as long as given, its heads as it bears them */
    private static function bus(WireLink $link, float $length): array
    {
        $end = $link->forward ? [[$length - 15, 0], [$length - 15, -5], [$length, 5], [$length - 15, 15], [$length - 15, 10]] : [[$length, 0], [$length, 10]];
        if ($link->backward) {
            return [[0, 5], [15, -5], [15, 0], ...$end, [15, 10], [15, 15]];
        }

        return [[0, 0], ...$end, [0, 10]];
    }

    /** @param list<array{int, int}> $points */
    private static function head(array $points, float $x, float $y, string $color): Mark
    {
        return new Mark(MarkKind::Polygon, \array_map(static fn (array $point): array => [$x + $point[0], $y + $point[1]], $points), stroke: 'none', fill: $color, strokeWidth: 0.0);
    }

    private static function circle(float $x, float $y, string $color): Mark
    {
        $points = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $points[] = [$x + (2.5 * \cos($angle)), $y + (2.5 * \sin($angle))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: $color, fill: $color, strokeWidth: 1.0);
    }

    /**
     * @param list<string> $lines
     * @return list<Mark> lines set left from a top left, one under another as PlantUML's sans-serif sets them
     */
    private static function text(array $lines, float $size, string $color, float $x, float $y): array
    {
        $marks = [];
        foreach ($lines as $index => $line) {
            $text = new PlantUmlText([$line], $size, color: $color);
            \array_push($marks, ...$text->marks($x, $y + ($index * $size * 1.177_734_375), $text->size()[0], 'left'));
        }

        return $marks;
    }
}
