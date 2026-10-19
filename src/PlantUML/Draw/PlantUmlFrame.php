<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Language\PlantUmlCaption;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;

/**
 * A diagram set in its document, as PlantUML sets it: the header at the top, the title above
 * the diagram, the legend below it, then the caption and the footer, each styled by the
 * document's style sheet; on the document's background, scaled by `scale`, in drawing units.
 */
final class PlantUmlFrame
{
    private const float MARGIN = 10.0;

    /** @param list<Mark> $marks the diagram's, in points, its top left at the origin */
    public static function drawing(PlantUmlDocument $document, array $marks, float $width, float $height): Drawing
    {
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
        $y = self::MARGIN;
        foreach ($blocks as $index => [$blockMarks, $blockWidth, $blockHeight, $align]) {
            $x = self::MARGIN
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
        $scale = Units::POINT * $document->scale;
        $background = $document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');

        return new Drawing(
            \max(1.0, ($full + (2 * self::MARGIN)) * $scale),
            \max(1.0, ($y + self::MARGIN) * $scale),
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
        $boxWidth = $textWidth + $padLeft + $padRight;
        $boxHeight = $textHeight + $padTop + $padBottom;
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

        return [$marks, $boxWidth + $marginLeft + $marginRight, $boxHeight + $marginTop + $marginBottom, $align];
    }

    private static function moved(Mark $mark, float $x, float $y): Mark
    {
        return new Mark(
            $mark->kind,
            \array_map(static fn (array $point): array => [$point[0] + $x, $point[1] + $y], $mark->points),
            $mark->text,
            $mark->stroke,
            $mark->fill,
            $mark->dashed,
            $mark->strokeWidth,
            $mark->font,
            $mark->fontScale,
            $mark->fillOpacity,
            $mark->opacity,
            $mark->angle,
            $mark->gradient,
            $mark->dashes,
        );
    }
}
