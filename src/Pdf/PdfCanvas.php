<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/**
 * One page's content stream and the resources it names: transparency states are shared by
 * value, fonts by instance, and fonts are written last, once every glyph they draw is known.
 */
final class PdfCanvas
{
    private string $content = '';

    /** @var array<string, string> state name by its dictionary */
    private array $states = [];

    /** @var array<int, array{string, PdfFont}> */
    private array $fonts = [];

    /** @var array<string, string> resource entries by kind: state, shading and image */
    private array $resources = ['ExtGState' => '', 'Shading' => '', 'XObject' => ''];

    private int $shadings = 0;
    private int $images = 0;

    public function __construct(
        private readonly PdfDocument $document,
    ) {}

    public function save(): void
    {
        $this->content .= "q\n";
    }

    public function restore(): void
    {
        $this->content .= "Q\n";
    }

    /** @param array{float, float, float, float, float, float} $matrix */
    public function transform(array $matrix): void
    {
        $this->content .= self::numbers($matrix)." cm\n";
    }

    /** @param array{float, float, float} $rgb channels from 0 to 1 */
    public function fillColor(array $rgb): void
    {
        $this->content .= self::numbers($rgb)." rg\n";
    }

    /** @param array{float, float, float} $rgb channels from 0 to 1 */
    public function strokeColor(array $rgb): void
    {
        $this->content .= self::numbers($rgb)." RG\n";
    }

    /** Constant fill and stroke opacity; fully opaque painting needs no state. */
    public function alpha(float $fill, float $stroke): void
    {
        [$fill, $stroke] = [\max(0.0, \min(1.0, $fill)), \max(0.0, \min(1.0, $stroke))];
        if (1.0 === $fill && 1.0 === $stroke) {
            return;
        }
        $state = '<< /Type /ExtGState /ca '.PdfDocument::number($fill).' /CA '.PdfDocument::number($stroke).' >>';
        if (!\array_key_exists($state, $this->states)) {
            $name = 'GS'.(\count($this->states) + 1);
            $this->states[$state] = $name;
            $this->resources['ExtGState'] .= '/'.$name.' '.$this->document->add($state).' 0 R ';
        }
        $this->content .= '/'.$this->states[$state]." gs\n";
    }

    public function lineWidth(float $width): void
    {
        $this->content .= PdfDocument::number($width)." w\n";
    }

    /**
     * A dash pattern; one PDF would reject (a negative length, or nothing but zeros) draws solid.
     *
     * @param list<float> $lengths
     */
    public function dash(array $lengths): void
    {
        $valid = \array_sum($lengths) > 0 && [] === \array_filter($lengths, static fn (float $length): bool => $length < 0);
        $this->content .= '['.($valid ? self::numbers($lengths) : '')."] 0 d\n";
    }

    /** @param list<array{float, float}> $points */
    public function polyline(array $points): void
    {
        foreach ($points as $index => $point) {
            $this->content .= self::numbers($point).(0 === $index ? " m\n" : " l\n");
        }
    }

    /** @param list<array{float, float}> $points */
    public function polygon(array $points): void
    {
        $this->polyline($points);
        $this->close();
    }

    /** Closes the current subpath back to its start. */
    public function close(): void
    {
        $this->content .= "h\n";
    }

    /** Fills the current path even-odd, its holes left out, ending it. */
    public function fillEvenOdd(): void
    {
        $this->content .= "f*\n";
    }

    /** Fills (nonzero winding, as SVG does), strokes, both or neither, ending the current path. */
    public function paint(bool $fill, bool $stroke): void
    {
        $this->content .= match (true) {
            $fill && $stroke => "B\n",
            $fill => "f\n",
            $stroke => "S\n",
            default => "n\n",
        };
    }

    /** Intersects the clip with the current path, ending it. */
    public function clip(): void
    {
        $this->content .= "W n\n";
    }

    /**
     * Paints the clip with an RGB blend along the line from one point to another, extended
     * past both ends.
     *
     * @param array{float, float, float} $from
     * @param array{float, float, float} $to
     * @param array{float, float, float, float} $coords
     */
    public function axial(array $from, array $to, array $coords, ?float $split = null): void
    {
        $id = $this->document->add(
            '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords ['.self::numbers($coords).'] /Function '.self::blend($from, $to, $split).' /Extend [true true] >>',
        );
        $name = 'Sh'.++$this->shadings;
        $this->resources['Shading'] .= '/'.$name.' '.$id.' 0 R ';
        $this->content .= '/'.$name." sh\n";
    }

    /**
     * A blend from one colour to another; or, split, the first up to the split and the second
     * after it, each flat.
     *
     * @param array{float, float, float} $from
     * @param array{float, float, float} $to
     */
    private static function blend(array $from, array $to, ?float $split): string
    {
        if (null === $split) {
            return '<< /FunctionType 2 /Domain [0 1] /C0 ['.self::numbers($from).'] /C1 ['.self::numbers($to).'] /N 1 >>';
        }

        return '<< /FunctionType 3 /Domain [0 1] /Functions ['.self::flat($from).' '.self::flat($to).'] /Bounds ['.self::numbers([\max(0.0001, \min(0.9999, $split))]).'] /Encode [0 1 0 1] >>';
    }

    /** @param array{float, float, float} $rgb */
    private static function flat(array $rgb): string
    {
        return '<< /FunctionType 2 /Domain [0 1] /C0 ['.self::numbers($rgb).'] /C1 ['.self::numbers($rgb).'] /N 1 >>';
    }

    /**
     * Paints the clip with an RGB blend out from one circle to another, extended past both.
     *
     * @param array{float, float, float} $from
     * @param array{float, float, float} $to
     * @param array{float, float, float, float, float, float} $coords the inner circle's centre and radius, then the outer's
     */
    public function radial(array $from, array $to, array $coords): void
    {
        $id = $this->document->add(
            '<< /ShadingType 3 /ColorSpace /DeviceRGB /Coords ['
            .self::numbers($coords)
            .'] /Function << /FunctionType 2 /Domain [0 1] /C0 ['
            .self::numbers($from)
            .'] /C1 ['
            .self::numbers($to)
            .'] /N 1 >> /Extend [true true] >>',
        );
        $name = 'Sh'.++$this->shadings;
        $this->resources['Shading'] .= '/'.$name.' '.$id.' 0 R ';
        $this->content .= '/'.$name." sh\n";
    }

    /**
     * Characters in a face at a font size of one, placed and sized by the text matrix.
     *
     * @param list<int> $codepoints
     * @param array{float, float, float, float, float, float} $matrix
     */
    public function text(PdfFont $font, array $codepoints, array $matrix): void
    {
        $key = \spl_object_id($font);
        $this->fonts[$key] ??= ['F'.(\count($this->fonts) + 1), $font];
        $this->content .= 'BT /'.$this->fonts[$key][0].' 1 Tf '.self::numbers($matrix).' Tm '.$font->encode($codepoints)." Tj ET\n";
    }

    /**
     * Draws an image over the unit square the matrix maps.
     *
     * @param array{float, float, float, float, float, float} $matrix
     */
    public function image(PdfImage $image, array $matrix): void
    {
        $dictionary = '/Type /XObject /Subtype /Image /Width '.$image->width.' /Height '.$image->height.' /BitsPerComponent 8';
        $mask = null === $image->alpha ? '' : ' /SMask '.$this->document->stream($dictionary.' /ColorSpace /DeviceGray', $image->alpha).' 0 R';
        $dictionary .= ' /ColorSpace /'.$image->colorSpace.$mask;
        $id = $image->jpeg ? $this->document->encoded($dictionary.' /Filter /DCTDecode', $image->data) : $this->document->stream($dictionary, $image->data);
        $name = 'Im'.++$this->images;
        $this->resources['XObject'] .= '/'.$name.' '.$id.' 0 R ';
        $this->save();
        $this->transform($matrix);
        $this->content .= '/'.$name." Do\n";
        $this->restore();
    }

    /** Writes the fonts, the content and a one-page tree sized in points; returns the catalog's number. */
    public function page(float $width, float $height): int
    {
        $fonts = '';
        foreach ($this->fonts as [$name, $font]) {
            $fonts .= '/'.$name.' '.$font->write($this->document).' 0 R ';
        }
        $resources = '/ProcSet [/PDF /Text /ImageB /ImageC] /Font << '.$fonts.'>>';
        foreach ($this->resources as $kind => $entries) {
            $resources .= ' /'.$kind.' << '.$entries.'>>';
        }
        $content = $this->document->stream('', $this->content);
        $pages = $this->document->reserve();
        $page = $this->document->add(
            '<< /Type /Page /Parent '.$pages.' 0 R /MediaBox [0 0 '.PdfDocument::number($width).' '.PdfDocument::number($height).'] /Resources << '.$resources.' >> /Contents '.$content.' 0 R >>',
        );
        $this->document->define($pages, '<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 >>');

        return $this->document->add('<< /Type /Catalog /Pages '.$pages.' 0 R >>');
    }

    /** @param array<array-key, float> $values */
    private static function numbers(array $values): string
    {
        return \implode(' ', \array_map(PdfDocument::number(...), $values));
    }
}
