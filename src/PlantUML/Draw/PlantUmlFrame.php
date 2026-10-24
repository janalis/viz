<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Language\PlantUmlCaption;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\Text\DisplayWidth;

/**
 * A diagram set in its document, as PlantUML sets it: the header at the top, the title above
 * the diagram, the legend below it, then the caption and the footer, each styled by the
 * document's style sheet; on the document's background, scaled by `scale`, in drawing units.
 */
final class PlantUmlFrame
{
    private const float MARGIN = 10.0;
    /** Where svek moves what it draws, from the top left, as its limit finder measures it. */
    private const float SVEK_INSET = 6.0;
    /** How much room svek leaves past what it draws. */
    private const float SVEK_TRAIL = 15.0;
    /** How much room svek leaves past an image it lays out within a diagram. */
    private const float SVEK_IMAGE_TRAIL = 15.0;
    /** How much of that room lies past the document's captions, below and to the right of everything. */
    private const float SVEK_OUTER = 5.0;

    /** @param list<Mark> $marks the diagram's, in points, its top left at the origin */
    public static function drawing(PlantUmlDocument $document, array $marks, float $width, float $height): Drawing
    {
        return self::framed($document, $marks, $width, $height, [self::MARGIN, self::MARGIN, self::MARGIN, self::MARGIN]);
    }

    /**
     * A diagram as {@see drawing()} frames it, its size rounded down to whole points, and some points past, as PlantUML is seen to round a board's, and a chart's 1 past.
     *
     * @param list<Mark> $marks the diagram's, in points, its top left at the origin
     */
    public static function roundedDown(PlantUmlDocument $document, array $marks, float $width, float $height, float $past = 0.0): Drawing
    {
        return self::framed($document, $marks, $width, $height, [self::MARGIN, self::MARGIN, self::MARGIN, self::MARGIN], static fn (float $size): float => \floor($size) + $past);
    }

    /**
     * A diagram PlantUML sets with no margin of its own, as a network diagram, its captions
     * still around it.
     *
     * @param list<Mark> $marks the diagram's, in points, its top left at the origin
     */
    public static function bare(PlantUmlDocument $document, array $marks, float $width, float $height): Drawing
    {
        return self::framed($document, $marks, $width, $height, [0.0, 0.0, 0.0, 0.0]);
    }

    /**
     * A diagram PlantUML sets with a margin of its own all round, as a salt wireframe's 5.
     *
     * @param list<Mark> $marks the diagram's, in points, its top left at the origin
     */
    public static function inset(PlantUmlDocument $document, array $marks, float $width, float $height, float $margin): Drawing
    {
        return self::framed($document, $marks, $width, $height, [$margin, $margin, $margin, $margin]);
    }

    /**
     * A sequence diagram, as PlantUML sets one laid out by teoz: 5 clear of the diagram all
     * round, which its captions stand outside of, and 5 more around everything.
     *
     * @param list<Mark> $marks the diagram's, in points, its top left at the origin
     */
    public static function teoz(PlantUmlDocument $document, array $marks, float $width, float $height): Drawing
    {
        $inner = self::MARGIN / 2;

        return self::framed($document, \array_map(static fn (Mark $mark): Mark => self::moved($mark, $inner, $inner), $marks), $width + (2 * $inner), $height + (2 * $inner), [
            $inner,
            $inner,
            $inner,
            $inner,
        ]);
    }

    /**
     * A diagram laid out by svek, as PlantUML sets one: what it draws moved to stand 6 in from
     * the top left, as PlantUML's limit finder measures it, 10 more room past it, and 5 more past everything, its captions included, its size rounded
     * down to whole points: as measured on PlantUML's output.
     *
     * @param list<Mark> $marks the diagram's, in points
     * @param float $trail the room past what it draws, the 5 past everything included
     */
    public static function svek(PlantUmlDocument $document, array $marks, float $trail = self::SVEK_TRAIL): Drawing
    {
        [$minX, $minY, $maxX, $maxY] = self::limits($marks);

        return self::framed(
            $document,
            \array_map(static fn (Mark $mark): Mark => self::moved($mark, self::SVEK_INSET - $minX, self::SVEK_INSET - $minY), $marks),
            $maxX - $minX + self::SVEK_INSET + $trail - self::SVEK_OUTER,
            $maxY - $minY + self::SVEK_INSET + $trail - self::SVEK_OUTER,
            [0.0, self::SVEK_OUTER, self::SVEK_OUTER, 0.0],
            \floor(...),
        );
    }

    /**
     * An image svek lays out within a diagram, as its result measures itself: what it draws
     * moved 6 in from its top left, as PlantUML's limit finder measures it, and 15 wider and
     * taller than that, as measured on PlantUML's output.
     *
     * @param list<Mark> $marks
     * @return array{list<Mark>, float, float} the marks moved, and the image's size
     */
    public static function svekImage(array $marks): array
    {
        [$minX, $minY, $maxX, $maxY] = self::limits($marks);

        return [
            \array_map(static fn (Mark $mark): Mark => self::moved($mark, self::SVEK_INSET - $minX, self::SVEK_INSET - $minY), $marks),
            $maxX - $minX + self::SVEK_IMAGE_TRAIL,
            $maxY - $minY + self::SVEK_IMAGE_TRAIL,
        ];
    }

    /**
     * The box PlantUML's limit finder gives marks: a box 1 short of its far sides and 1 before
     * its near ones, as it finds rectangles, an ellipse from its near sides to 1 short of its far
     * ones, a path and a line as they run; a text its line's box.
     *
     * @param list<Mark> $marks
     * @return array{float, float, float, float}
     */
    private static function limits(array $marks): array
    {
        $box = [\INF, \INF, -\INF, -\INF];
        foreach ($marks as $mark) {
            foreach (self::extent($mark) as [$x, $y]) {
                $box = [\min($box[0], $x), \min($box[1], $y), \max($box[2], $x), \max($box[3], $y)];
            }
        }

        return \INF === $box[0] ? [0.0, 0.0, 0.0, 0.0] : $box;
    }

    /** @return list<array{float, float}> */
    private static function extent(Mark $mark): array
    {
        if (MarkKind::Text === $mark->kind) {
            [$x, $y] = $mark->points[0];
            $half = (null === $mark->slots ? DisplayWidth::of($mark->text) * $mark->fontScale : \array_sum($mark->slots)) / 2;
            // As PlantUML's limit finder takes a text, as measured on its output: its line moved 1.5 down from its baseline's top.
            $size = $mark->fontScale * Units::POINT * 12;

            return [[$x - $half, $y - PlantUmlText::lineHeight($size) + 1.5], [$x + $half, $y + 1.5]];
        }
        if (MarkKind::Polygon === $mark->kind) {
            [$near, $far] = match ($mark->outline) {
                Outline::Box => [1.0, 1.0],
                Outline::Path => [0.0, 0.0],
                Outline::Ellipse => [0.0, 1.0],
                Outline::Block => [1.0, 0.0],
            };
            $xs = \array_column($mark->points, 0);
            $ys = \array_column($mark->points, 1);

            return [[\min($xs) - $near, \min($ys) - $near], [\max($xs) - $far, \max($ys) - $far]];
        }

        return $mark->points;
    }

    /**
     * @param list<Mark> $marks
     * @param array{float, float, float, float} $margins top, right, bottom and left, around everything, unless the document's style sets them
     * @param ?\Closure(float): float $whole how its size unscaled is rounded to whole points: up by default, as PlantUML writes it
     */
    private static function framed(PlantUmlDocument $document, array $marks, float $width, float $height, array $margins, ?\Closure $whole = null): Drawing
    {
        // A margin the document's style sets, as a theme's `root` does, stands for the diagram's own.
        $style = $document->styles->style(['root', 'document']);
        [$top, $right, $bottom, $left] = '' === \trim($style->value('Margin')) ? $margins : $style->sides('Margin');
        $blocks = [];
        foreach (['header', 'title'] as $kind) {
            $blocks[] = self::block($document, $kind);
        }
        $blocks[] = [[], $width, $height, 'center'];
        foreach (['legend', 'caption', 'footer'] as $kind) {
            $blocks[] = self::block($document, $kind);
        }
        $full = \max(\array_map(static fn (array $block): float => $block[1], $blocks));
        $placed = [];
        $y = $top;
        foreach ($blocks as $index => [$blockMarks, $blockWidth, $blockHeight, $align]) {
            $x = $left
            + match ($align) {
                'left' => 0.0,
                'right' => $full - $blockWidth,
                default => ($full - $blockWidth) / 2,
            };
            $shown = 2 === $index ? $marks : $blockMarks;
            foreach ($shown as $mark) {
                $placed[] = self::moved($mark, $x, $y);
            }
            $y += $blockHeight;
        }
        // Fitted to a size, a drawing rounded down to whole points, as svek's, is fitted from that size, any other from its own, as measured on PlantUML's output.
        $basis = $whole ?? static fn (float $size): float => $size;
        $factor = $document->scaleFactor($basis($full + $left + $right), $basis($y + $bottom));
        $scale = Units::POINT * $factor;
        $whole ??= \ceil(...);
        // As PlantUML writes its image's size: in whole points as it is unscaled, then scaled and rounded down.
        $sized = static fn (float $size): float => \max(1.0, \floor(($whole($size) * $factor) + 1e-9) * Units::POINT);
        $background = $document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');

        return new Drawing(
            $sized($full + $left + $right),
            $sized($y + $bottom),
            \array_map(static fn (Mark $mark): Mark => Units::scaled($mark, $scale), $placed),
            'none' === $background ? null : $background,
        );
    }

    /** @return array{list<Mark>, float, float, string} a caption's marks from the origin, its size, and how it aligns */
    private static function block(PlantUmlDocument $document, string $kind): array
    {
        $caption = $document->captions[$kind] ?? null;
        if (!$caption instanceof PlantUmlCaption) {
            return [[], 0.0, 0.0, 'center'];
        }
        $style = $document->styles->style(['root', 'document', $kind]);
        $text = PlantUmlText::styled($caption->lines, $style);
        [$textWidth, $textHeight] = $text->size();
        [$padTop, $padRight, $padBottom, $padLeft] = $style->sides('Padding');
        [$marginTop, $marginRight, $marginBottom, $marginLeft] = $style->sides('Margin');
        // As PlantUML's bordered text block, 1 more each way than its text and padding.
        $boxWidth = $textWidth + $padLeft + $padRight + 1;
        $boxHeight = $textHeight + $padTop + $padBottom + 1;
        $marks = [];
        $line = $style->color('LineColor', 'none');
        $back = $style->color('BackGroundColor', 'none');
        if ('none' !== $line || 'none' !== $back) {
            $marks[] = new Mark(
                MarkKind::Polygon,
                [[$marginLeft, $marginTop], [$marginLeft + $boxWidth, $marginTop], [$marginLeft + $boxWidth, $marginTop + $boxHeight], [$marginLeft, $marginTop + $boxHeight]],
                stroke: $line,
                fill: $back,
                strokeWidth: $style->number('LineThickness', 1.0),
            );
        }
        $align = $caption->horizontal ?? \strtolower($style->value('HorizontalAlignment', 'center'));
        \array_push($marks, ...$text->marks($marginLeft + $padLeft, $marginTop + $padTop, $textWidth, $align));

        // As PlantUML is seen to take its width, as measured on its output: rounded up to whole points.
        return [$marks, \ceil($boxWidth + $marginLeft + $marginRight), $boxHeight + $marginTop + $marginBottom, $align];
    }

    private static function moved(Mark $mark, float $x, float $y): Mark
    {
        return Units::moved($mark, $x, $y);
    }
}
