<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Text\Font;
use Janalis\Viz\Text\FontFace;

/** Inert, self-contained SVG: diagram text is text, never markup or executable content. */
final class SvgBackend
{
    /** @return \Generator<int, null, mixed, string> */
    public static function render(Drawing $drawing): \Generator
    {
        $width = (string) $drawing->width;
        $height = (string) $drawing->height;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.\ceil($drawing->width * 10).'" height="'.\ceil($drawing->height * 10).'" viewBox="0 0 '.$width.' '.$height.'">';
        if (null !== $drawing->background) {
            $svg .= '<rect width="100%" height="100%" fill="'.self::escape($drawing->background).'"/>';
        }
        $faces = [FontFace::Regular->value => FontFace::Regular];
        foreach ($drawing->marks as $mark) {
            $faces[$mark->font->value] = $mark->font;
            yield null;
        }
        $svg .= '<style>';
        foreach ($faces as $face) {
            $font = (string) \file_get_contents(Font::file($face));
            $svg .= '@font-face{font-family:VizMermaid;font-style:'.$face->style().';font-weight:'.$face->weight().';src:url(data:font/ttf;base64,'.\base64_encode($font).')}';
            yield null;
        }
        $svg .= 'text{font-family:VizMermaid,monospace,sans-serif;font-size:1.6px;text-anchor:middle}</style>';
        $svg .= '<metadata>'.self::escape((string) \file_get_contents(__DIR__.'/../../resources/fonts/OFL.txt')).'</metadata>';
        foreach ($drawing->marks as $index => $mark) {
            $svg .= self::mark($mark, $index);
            yield null;
        }

        return $svg.'</svg>';
    }

    private static function mark(Mark $mark, int $index): string
    {
        return match ($mark->kind) {
            MarkKind::Image => self::image($mark),
            MarkKind::Text => self::text($mark),
            default => self::gradient($mark, 'gradient-'.$index).self::outline($mark, null === $mark->gradient ? $mark->fill : 'url(#gradient-'.$index.')'),
        };
    }

    /** A polygon's gradient: across its box at its angle ({@see GradientAxis}), left to right by default, or out from its box's centre. */
    private static function gradient(Mark $mark, string $id): string
    {
        if (null === $mark->gradient) {
            return '';
        }
        $xs = \array_column($mark->points, 0);
        $radial = Shading::Radial === $mark->shading;
        // A split, as dot draws one, is two stops a thousandth apart; a radial blend never splits.
        $offsets = null === $mark->gradientSplit || $radial ? [0, 1] : [\round(\max(0.0, $mark->gradientSplit - 0.001), precision: 4), \round($mark->gradientSplit, precision: 4)];
        $stops = '';
        foreach ($mark->gradient as $index => $color) {
            $alpha = 9 === \strlen($color) ? \hexdec(\substr($color, offset: 7)) / 255 : 1.0;
            $stops .= '<stop offset="'.$offsets[$index].'" stop-color="'.self::escape(\substr($color, offset: 0, length: 7)).'" stop-opacity="'.\round($alpha, precision: 4).'"/>';
        }

        if ($radial) {
            [$fx, $fy] = GradientAxis::focus($mark->gradientAngle);
            $focus = 0.0 === $mark->gradientAngle ? '' : ' fx="'.\round(100 * $fx, precision: 2).'%" fy="'.\round(100 * $fy, precision: 2).'%"';

            return '<radialGradient id="'.$id.'" cx="50%" cy="50%" r="75%"'.$focus.'>'.$stops.'</radialGradient>';
        }
        if (0.0 === $mark->gradientAngle) {
            return '<linearGradient id="'.$id.'" gradientUnits="userSpaceOnUse" x1="'.\min($xs).'" y1="0" x2="'.\max($xs).'" y2="0">'.$stops.'</linearGradient>';
        }
        [[$x1, $y1], [$x2, $y2]] = GradientAxis::ends($mark->points, $mark->gradientAngle);

        return '<linearGradient id="'.$id.'" gradientUnits="userSpaceOnUse" x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'">'.$stops.'</linearGradient>';
    }

    /** The URI was rebuilt from validated image bytes, never copied from the document. */
    private static function image(Mark $mark): string
    {
        [[$left, $top], [$right, $bottom]] = $mark->points;

        return '<image x="'.$left.'" y="'.$top.'" width="'.($right - $left).'" height="'.($bottom - $top).'" preserveAspectRatio="none" href="'.self::escape($mark->text).'"/>';
    }

    private static function text(Mark $mark): string
    {
        [$x, $y] = $mark->points[0];

        $style = FontFace::Regular === $mark->font ? '' : ' font-style="'.$mark->font->style().'" font-weight="'.$mark->font->weight().'"';

        $style .= 1.0 === $mark->fontScale ? '' : ' style="font-size:'.(1.6 * $mark->fontScale).'px"';
        $style .= 0.0 === $mark->angle ? '' : ' transform="rotate('.$mark->angle.' '.$x.' '.$y.')"';

        $centres = Mark::centres($mark);
        if (null === $centres) {
            return '<text x="'.$x.'" y="'.$y.'" fill="'.self::escape($mark->stroke).'"'.$style.'>'.self::escape($mark->text).'</text>';
        }
        // Each character in the middle of its own room, a chunk of its own as every renderer sets one; a blank draws nothing.
        $spans = '';
        foreach (\mb_str_split($mark->text) as $index => $character) {
            $spans .= '' === \trim($character) ? '' : '<tspan x="'.\round($x + $centres[$index], precision: 4).'">'.self::escape($character).'</tspan>';
        }

        return '<text y="'.$y.'" fill="'.self::escape($mark->stroke).'"'.$style.'>'.$spans.'</text>';
    }

    private static function outline(Mark $mark, string $fill): string
    {
        $points = \implode(' ', \array_map(static fn (array $point): string => $point[0].','.$point[1], $mark->points));
        $tag = MarkKind::Polygon === $mark->kind ? 'polygon' : 'polyline';
        $fill = MarkKind::Polygon === $mark->kind ? $fill : 'none';
        // A polygon with other outlines, its holes, is one path filled by its rule.
        $shape =
            MarkKind::Polygon === $mark->kind && [] !== $mark->subpaths
                ? 'path d="'
                .\implode(' ', \array_map(static fn (array $outline): string => 'M'.\implode(' L', \array_map(static fn (array $point): string => $point[0].','.$point[1], $outline)).' Z', [
                    $mark->points,
                    ...$mark->subpaths,
                ]))
                .'" fill-rule="'
                .(FillRule::NonZero === $mark->fillRule ? 'nonzero' : 'evenodd')
                : $tag.' points="'.$points;

        return (
            '<'
            .$shape
            .'" stroke="'
            .self::escape($mark->stroke)
            .'" fill="'
            .self::escape($fill)
            .'" stroke-width="'
            .$mark->strokeWidth
            .'"'
            .(1.0 === $mark->fillOpacity ? '' : ' fill-opacity="'.$mark->fillOpacity.'"')
            .(1.0 === $mark->opacity ? '' : ' opacity="'.$mark->opacity.'"')
            .($mark->dashed ? ' stroke-dasharray="'.\implode(' ', \array_map(static fn (float $length): string => (string) \round($length, precision: 4), $mark->dashes ?? [0.6, 0.4])).'"' : '')
            .'/>'
        );
    }

    private static function escape(string $text): string
    {
        $text = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', replacement: '', subject: $text) ?? $text;

        return \htmlspecialchars($text, flags: \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE, encoding: 'UTF-8');
    }
}
