<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/**
 * A TrueType face embedded whole as a Type0 font: Identity-H codes are glyph ids, and the
 * glyphs drawn decide the width array and the text a reader extracts.
 */
final class PdfFont
{
    /** @var array<int, int> every glyph drawn, by itself */
    private array $glyphs = [];

    /** @var array<int, int> the first character each glyph drew */
    private array $characters = [];

    public function __construct(
        public readonly TrueTypeFont $font,
    ) {}

    /**
     * The characters as a hex glyph-id string, remembered for the widths and the text map.
     *
     * @param list<int> $codepoints
     */
    public function encode(array $codepoints): string
    {
        $hex = '';
        foreach ($codepoints as $codepoint) {
            $glyph = $this->font->glyph($codepoint);
            $this->glyphs[$glyph] = $glyph;
            if (0 !== $glyph) {
                $this->characters[$glyph] ??= $codepoint;
            }
            $hex .= \sprintf('%04X', $glyph);
        }

        return '<'.$hex.'>';
    }

    /**
     * The characters' advance at a font size of one.
     *
     * @param list<int> $codepoints
     */
    public function width(array $codepoints): float
    {
        $units = 0;
        foreach ($codepoints as $codepoint) {
            $units += $this->font->advance($this->font->glyph($codepoint));
        }

        return $units / $this->font->unitsPerEm;
    }

    /** The font's objects; returns the Type0 font's number. */
    public function write(PdfDocument $document): int
    {
        $font = $this->font;
        $file = $document->stream('/Length1 '.\strlen($font->bytes), $font->bytes);
        $flags = 32 | ($font->fixedPitch ? 1 : 0) | (0.0 === $font->italicAngle ? 0 : 64);
        $descriptor = $document->add(\sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%s] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
            $font->name,
            $flags,
            \implode(' ', \array_map($this->scaled(...), $font->box)),
            PdfDocument::number($font->italicAngle),
            $this->scaled($font->ascent),
            $this->scaled($font->descent),
            $this->scaled($font->capHeight),
            // PDF wants a dominant stem width; a fifth of the weight class approximates it.
            \intdiv($font->weight, num2: 5),
            $file,
        ));
        \ksort($this->glyphs);
        $widths = '';
        foreach ($this->glyphs as $glyph) {
            $widths .= $glyph.' ['.$this->scaled($font->advance($glyph)).'] ';
        }
        $cid = $document->add(\sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /CIDToGIDMap /Identity /W [%s] >>',
            $font->name,
            $descriptor,
            \rtrim($widths),
        ));
        $unicode = $document->stream('', PdfToUnicode::cmap($this->characters));

        return $document->add('<< /Type /Font /Subtype /Type0 /BaseFont /'.$font->name.' /Encoding /Identity-H /DescendantFonts ['.$cid.' 0 R] /ToUnicode '.$unicode.' 0 R >>');
    }

    /** Font units in the thousandths of an em PDF font dictionaries use. */
    private function scaled(int $units): int
    {
        return (int) \round(($units * 1000) / $this->font->unitsPerEm);
    }
}
