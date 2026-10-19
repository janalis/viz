<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Mermaid\MermaidFont;
use Janalis\Viz\Pdf\PdfDocument;
use Janalis\Viz\Pdf\PdfFont;
use Janalis\Viz\Pdf\TrueTypeFont;
use PHPUnit\Framework\TestCase;

/** A whole TrueType face as a Type0 font whose glyphs keep their widths and their text. */
final class PdfFontTest extends TestCase
{
    public function testCharactersEncodeAsGlyphIdsAndMeasureByTheirAdvances(): void
    {
        $font = self::synthetic();
        $this->assertSame('<0001000200000001>', $font->encode([0x41, 0x42, 0x43, 0x41]));
        $this->assertSame('<>', $font->encode([]));
        $this->assertSame((600 + 500) / 2048, $font->width([0x41, 0x43]));
        $this->assertSame(0.0, $font->width([]));
    }

    public function testTheFontIsWrittenWithItsDescriptorWidthsAndTextMap(): void
    {
        $font = self::synthetic();
        $font->encode([0x42, 0x43, 0x41, 0x61, 0x1_F60E]);
        $document = new PdfDocument(compress: false);
        $this->assertSame(5, $font->write($document));
        $pdf = $document->bytes(5, producer: 'p');
        $bytes = $font->font->bytes;
        $this->assertStringContainsString("1 0 obj\n<< /Length1 ".\strlen($bytes).' /Length '.\strlen($bytes)." >>\nstream\n".$bytes."\nendstream\nendobj", $pdf);
        $this->assertStringContainsString(
            "2 0 obj\n<< /Type /FontDescriptor /FontName /Synthetic-Face /Flags 96 /FontBBox [-16000 -146 928 977] /ItalicAngle -12.5 /Ascent 879 /Descent -195 /CapHeight 684 /StemV 140 /FontFile2 1 0 R >>\nendobj",
            $pdf,
        );
        $this->assertStringContainsString(
            "3 0 obj\n<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Synthetic-Face /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 2 0 R /CIDToGIDMap /Identity /W [0 [244] 1 [293] 2 [342] 3 [342]] >>\nendobj",
            $pdf,
        );
        $this->assertStringContainsString("3 beginbfchar\n<0001> <0041>\n<0002> <0042>\n<0003> <D83DDE0E>\nendbfchar\n", $pdf, '.notdef has no text, and a shared glyph keeps its first character');
        $this->assertStringContainsString("5 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /Synthetic-Face /Encoding /Identity-H /DescendantFonts [3 0 R] /ToUnicode 4 0 R >>\nendobj", $pdf);
    }

    public function testFixedPitchUprightFacesSetOnlyThoseFlags(): void
    {
        $font = new PdfFont(TrueTypeFont::file(MermaidFont::FILE) ?? throw new \LogicException('The bundled face is missing'));
        $document = new PdfDocument(compress: false);
        $font->write($document);
        $pdf = $document->bytes(1, producer: 'p');
        $this->assertStringContainsString('/FontName /IBMPlexMono-Regular /Flags 33 /FontBBox [-260 -350 648 1119] /ItalicAngle 0 /Ascent 1025 /Descent -275 /CapHeight 698 /StemV 80 ', $pdf);
        $this->assertStringContainsString('/W []', $pdf);
    }

    public function testUprightProportionalFacesAreOnlyNonsymbolic(): void
    {
        $tables = TrueTypeFontTest::tables([[0x41, 0x41, 1]]);
        unset($tables['post']);
        $font = new PdfFont(TrueTypeFont::parse(TrueTypeFontTest::sfnt($tables)) ?? throw new \LogicException('The synthetic face does not parse'));
        $document = new PdfDocument(compress: false);
        $font->write($document);
        $this->assertStringContainsString('/FontName /Synthetic-Face /Flags 32 /FontBBox', $document->bytes(1, producer: 'p'));
    }

    private static function synthetic(): PdfFont
    {
        $font = TrueTypeFont::parse(TrueTypeFontTest::font([
            [0x41,     0x42,     1],
            [0x61,     0x61,     1],
            [0x1_F60E, 0x1_F60E, 3],
        ]));

        return new PdfFont($font ?? throw new \LogicException('The synthetic face does not parse'));
    }
}
