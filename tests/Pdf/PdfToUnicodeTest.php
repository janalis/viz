<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Pdf\PdfToUnicode;
use PHPUnit\Framework\TestCase;

/** Glyph codes back to text, astral characters included. */
final class PdfToUnicodeTest extends TestCase
{
    public function testCharactersAreUtf16WithSurrogatePairsBeyondTheBasicPlane(): void
    {
        $this->assertSame('0041', PdfToUnicode::utf16(0x41));
        $this->assertSame('FFFF', PdfToUnicode::utf16(0xFFFF));
        $this->assertSame('D800DC00', PdfToUnicode::utf16(0x1_0000));
        $this->assertSame('D83DDE0E', PdfToUnicode::utf16(0x1_F60E));
        $this->assertSame('DBFFDFFF', PdfToUnicode::utf16(0x10_FFFF));
    }

    public function testTheMapListsGlyphsInOrder(): void
    {
        $this->assertSame(
            "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            ."2 beginbfchar\n<0005> <D83DDE0E>\n<001E> <0041>\nendbfchar\n"
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend",
            PdfToUnicode::cmap([0x1E => 0x41, 0x05 => 0x1_F60E]),
        );
    }

    public function testLongMapsSplitIntoBlocksOfAHundred(): void
    {
        $cmap = PdfToUnicode::cmap(\array_fill(1, count: 150, value: 0x41));
        $this->assertSame(2, \substr_count($cmap, needle: 'beginbfchar'));
        $this->assertStringContainsString("100 beginbfchar\n<0001> <0041>\n", $cmap);
        $this->assertStringContainsString("<0064> <0041>\nendbfchar\n50 beginbfchar\n<0065> <0041>\n", $cmap);
        $this->assertStringNotContainsString('beginbfchar', PdfToUnicode::cmap([]));
    }
}
