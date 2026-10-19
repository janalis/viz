<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Color\ColorValue;
use Janalis\Viz\Pdf\PdfCanvas;
use Janalis\Viz\Pdf\PdfDocument;
use Janalis\Viz\Pdf\PdfFont;
use Janalis\Viz\Pdf\PdfFonts;
use Janalis\Viz\Pdf\PdfImage;
use Janalis\Viz\Text\FallbackFonts;
use Janalis\Viz\Text\Font;

/**
 * Native vector backend: one page the drawing's size, marks in drawing units under a single
 * flipped transform, text as embedded glyphs a reader can still select and search.
 */
final readonly class PdfBackend
{
    /** Points per drawing unit: a unit is ten CSS pixels, a CSS pixel three quarters of a point. */
    public const float POINTS = 7.5;

    private bool $gd;

    public function __construct(
        private int $maxPixels = PngBackend::MAX_PIXELS,
        ?bool $gdAvailable = null,
        private string $font = Font::FILE,
        private FallbackFonts $fallbacks = new FallbackFonts(),
        private ?bool $compress = null,
    ) {
        $this->gd = $gdAvailable ?? \function_exists('imagecreatefromstring');
    }

    /** @return \Generator<int, null, mixed, string> */
    public function render(Drawing $drawing): \Generator
    {
        if ($drawing->width < 1 || $drawing->height < 1 || !\is_finite($drawing->width) || !\is_finite($drawing->height)) {
            throw new \InvalidArgumentException('Invalid PDF dimensions');
        }
        $document = new PdfDocument($this->compress);
        $canvas = new PdfCanvas($document);
        $fonts = new PdfFonts();
        $canvas->transform([self::POINTS, 0.0, 0.0, -self::POINTS, 0.0, $drawing->height * self::POINTS]);
        if (null !== $drawing->background) {
            [$rgb, $alpha] = self::color($drawing->background);
            $canvas->save();
            $canvas->alpha($alpha, stroke: 1.0);
            $canvas->fillColor($rgb);
            $canvas->polygon([[0.0, 0.0], [$drawing->width, 0.0], [$drawing->width, $drawing->height], [0.0, $drawing->height]]);
            $canvas->paint(fill: true, stroke: false);
            $canvas->restore();
        }
        foreach ($drawing->marks as $mark) {
            match ($mark->kind) {
                MarkKind::Image => $this->image($canvas, $mark),
                MarkKind::Text => $this->text($canvas, $fonts, $mark),
                default => self::outline($canvas, $mark),
            };
            yield null;
        }
        $catalog = $canvas->page($drawing->width * self::POINTS, $drawing->height * self::POINTS);

        return $document->bytes($catalog, producer: 'janalis/viz');
    }

    private static function outline(PdfCanvas $canvas, Mark $mark): void
    {
        $polygon = MarkKind::Polygon === $mark->kind;
        if ($polygon && null !== $mark->gradient && [] !== $mark->points) {
            self::gradient($canvas, $mark, $mark->gradient);
        }
        $filled = $polygon && null === $mark->gradient && 'none' !== $mark->fill;
        $stroked = $mark->strokeWidth > 0.0 && 'none' !== $mark->stroke;
        if ([] === $mark->points || (!$filled && !$stroked)) {
            return;
        }
        [$fill, $fillAlpha] = self::color($mark->fill);
        [$stroke, $strokeAlpha] = self::color($mark->stroke);
        $canvas->save();
        // A paint that is not drawn needs no transparency state of its own.
        $canvas->alpha($filled ? $fillAlpha * $mark->fillOpacity * $mark->opacity : 1.0, $stroked ? $strokeAlpha * $mark->opacity : 1.0);
        $canvas->fillColor($fill);
        $canvas->strokeColor($stroke);
        $canvas->lineWidth($mark->strokeWidth);
        if ($mark->dashed) {
            $canvas->dash($mark->dashes ?? [0.6, 0.4]);
        }
        $canvas->polyline($mark->points);
        if ($polygon) {
            $canvas->close();
        }
        $canvas->paint($filled, $stroked);
        $canvas->restore();
    }

    /**
     * A left-to-right blend across the polygon's own horizontal extent, clipped to it; PDF
     * shadings blend color only, so the first stop's alpha stands for both.
     *
     * @param array{string, string} $stops
     */
    private static function gradient(PdfCanvas $canvas, Mark $mark, array $stops): void
    {
        $xs = \array_column($mark->points, 0);
        [$from, $alpha] = self::color($stops[0]);
        [$to] = self::color($stops[1]);
        $canvas->save();
        $canvas->polygon($mark->points);
        $canvas->clip();
        $canvas->alpha($alpha * $mark->fillOpacity * $mark->opacity, stroke: 1.0);
        // An axial shading needs two distinct ends; a zero-width polygon paints nothing anyway.
        $canvas->axial($from, $to, [\min($xs), 0.0, \max(\max($xs), \min($xs) + 0.001), 0.0]);
        $canvas->restore();
    }

    /**
     * One line centered on its anchor, the baseline through it, turned clockwise about it; the
     * text matrix flips y back so glyphs stand upright under the page transform.
     */
    private function text(PdfCanvas $canvas, PdfFonts $fonts, Mark $mark): void
    {
        $text = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', replacement: '', subject: \mb_scrub($mark->text, encoding: 'UTF-8')) ?? '';
        if ('' === $text || [] === $mark->points) {
            return;
        }
        $size = 1.6 * $mark->fontScale;
        $runs = $this->runs($fonts, $text, $mark);
        $width = \array_sum(\array_map(static fn (array $run): float => $run[0]->width($run[1]) * $size, $runs));
        $radians = \deg2rad($mark->angle);
        [$cos, $sin] = [\cos($radians), \sin($radians)];
        [$x, $y] = $mark->points[0];
        $x -= ($cos * $width) / 2;
        $y -= ($sin * $width) / 2;
        [$rgb, $alpha] = self::color($mark->stroke);
        $canvas->save();
        $canvas->alpha($alpha * $mark->opacity, stroke: 1.0);
        $canvas->fillColor($rgb);
        foreach ($runs as [$font, $codepoints]) {
            $canvas->text($font, $codepoints, [$cos * $size, $sin * $size, $sin * $size, -$cos * $size, $x, $y]);
            $advance = $font->width($codepoints) * $size;
            $x += $cos * $advance;
            $y += $sin * $advance;
        }
        $canvas->restore();
    }

    /**
     * The text in runs of one face: the bundled face where it has the glyph, else the first
     * host fallback that draws it and can be embedded, else the bundled face's `.notdef`.
     *
     * @return list<array{PdfFont, list<int>}>
     */
    private function runs(PdfFonts $fonts, string $text, Mark $mark): array
    {
        $bundled = $fonts->get(Font::file($mark->font, $this->font)) ?? throw new \InvalidArgumentException('Cannot read the bundled font');
        /** @var list<array{PdfFont, list<int>}> $runs */
        $runs = [];
        foreach (\mb_str_split($text) as $character) {
            $codepoint = (int) \mb_ord($character);
            $font = 0 !== $bundled->font->glyph($codepoint) ? $bundled : $this->fallback($fonts, $codepoint) ?? $bundled;
            $last = \array_key_last($runs);
            if (null !== $last && $runs[$last][0] === $font) {
                $runs[$last][1][] = $codepoint;
                continue;
            }
            $runs[] = [$font, [$codepoint]];
        }

        return $runs;
    }

    private function fallback(PdfFonts $fonts, int $codepoint): ?PdfFont
    {
        $path = $this->fallbacks->font($codepoint);

        return null === $path ? null : $fonts->get($path);
    }

    /** An embedded picture within the pixel budget, stretched to its box; undecodable ones are skipped. */
    private function image(PdfCanvas $canvas, Mark $mark): void
    {
        $bytes = (string) \base64_decode(\explode(',', $mark->text, limit: 2)[1] ?? '', strict: true);
        $picture = $this->decode($bytes);
        if (null === $picture || \count($mark->points) < 2) {
            return;
        }
        [[$left, $top], [$right, $bottom]] = $mark->points;
        $canvas->image($picture, [$right - $left, 0.0, 0.0, $top - $bottom, $left, $bottom]);
    }

    /**
     * The header's size is read BEFORE decoding: a few KiB of PNG can declare a 50 000²
     * bitmap, and GD would allocate it in full outside memory_limit.
     */
    private function decode(string $bytes): ?PdfImage
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $size = \getimagesizefromstring($bytes);
            if (false === $size || ($size[0] * $size[1]) > $this->maxPixels) {
                return null;
            }
            $jpeg = PdfImage::jpeg($bytes);
            if (null !== $jpeg || !$this->gd) {
                return $jpeg;
            }
            $picture = \imagecreatefromstring($bytes);
        } finally {
            \restore_error_handler();
        }

        return $picture instanceof \GdImage ? PdfImage::gd($picture) : null;
    }

    /**
     * RGB channels from 0 to 1 and the alpha an eight-digit hex color carries; unreadable
     * colors draw in the default ink, as the raster backend does.
     *
     * @return array{array{float, float, float}, float}
     */
    private static function color(string $value): array
    {
        $rgb = (int) \hexdec(\substr(ColorValue::normalize($value) ?? '#333333', offset: 1));
        $alpha = 1 === \preg_match('/^#[0-9a-f]{8}$/i', $value) ? \hexdec(\substr($value, offset: -2)) / 255 : 1.0;

        return [[(($rgb >> 16) & 0xFF) / 255, (($rgb >> 8) & 0xFF) / 255, ($rgb & 0xFF) / 255], (float) $alpha];
    }
}
