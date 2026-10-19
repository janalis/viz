<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Mermaid\MermaidFont;
use Janalis\Viz\Pdf\PdfCanvas;
use Janalis\Viz\Pdf\PdfDocument;
use Janalis\Viz\Pdf\PdfFont;
use Janalis\Viz\Pdf\PdfImage;
use Janalis\Viz\Pdf\TrueTypeFont;
use PHPUnit\Framework\TestCase;

/** Content-stream operators and the page resources they name. */
final class PdfCanvasTest extends TestCase
{
    public function testAnEmptyPageNamesEmptyResources(): void
    {
        $document = new PdfDocument(compress: false);
        $catalog = new PdfCanvas($document)->page(300.0, 150.5);
        $pdf = $document->bytes($catalog, producer: 'p');
        $this->assertSame(4, $catalog);
        $this->assertStringContainsString("1 0 obj\n<< /Length 0 >>\nstream\n\nendstream", $pdf);
        $this->assertStringContainsString(
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 150.5] /Resources << /ProcSet [/PDF /Text /ImageB /ImageC] /Font << >> /ExtGState << >> /Shading << >> /XObject << >> >> /Contents 1 0 R >>\nendobj",
            $pdf,
        );
        $this->assertStringContainsString("2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj", $pdf);
        $this->assertStringContainsString("4 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj", $pdf);
    }

    public function testPathsArePaintedByTheOperatorTheirFillAndStrokeAsk(): void
    {
        [$document, $canvas] = self::canvas();
        $canvas->save();
        $canvas->transform([7.5, 0.0, 0.0, -7.5, 0.0, 150.0]);
        $canvas->fillColor([1.0, 0.5, 0.0]);
        $canvas->strokeColor([0.0, 0.0, 1.0 / 3]);
        $canvas->lineWidth(0.12);
        $canvas->polygon([[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]]);
        $canvas->paint(fill: true, stroke: true);
        $canvas->polyline([[1.0, 2.0], [3.0, 4.0]]);
        $canvas->paint(fill: true, stroke: false);
        $canvas->polyline([]);
        $canvas->paint(fill: false, stroke: true);
        $canvas->paint(fill: false, stroke: false);
        $canvas->clip();
        $canvas->restore();
        $this->assertSame(
            "q\n7.5 0 0 -7.5 0 150 cm\n1 0.5 0 rg\n0 0 0.3333 RG\n0.12 w\n1 2 m\n3 4 l\n5 6 l\nh\nB\n1 2 m\n3 4 l\nf\nS\nn\nW n\nQ\n",
            self::content($document, $canvas),
        );
    }

    public function testDashPatternsPdfWouldRejectDrawSolid(): void
    {
        [$document, $canvas] = self::canvas();
        $canvas->dash([0.6, 0.4]);
        $canvas->dash([0.0, 1.0]);
        $canvas->dash([]);
        $canvas->dash([0.0, 0.0]);
        $canvas->dash([1.0, -0.5, 2.0]);
        $this->assertSame("[0.6 0.4] 0 d\n[0 1] 0 d\n[] 0 d\n[] 0 d\n[] 0 d\n", self::content($document, $canvas));
    }

    public function testTransparencyStatesAreSharedByValueAndClamped(): void
    {
        [$document, $canvas] = self::canvas();
        $canvas->alpha(1.0, stroke: 1.0);
        $canvas->alpha(0.5, stroke: 1.0);
        $canvas->alpha(0.5, stroke: 1.0);
        $canvas->alpha(1.0, stroke: 0.25);
        $canvas->alpha(1.5, stroke: -1.0);
        $canvas->alpha(1.0, stroke: 0.0);
        $pdf = self::pdf($document, $canvas);
        $this->assertSame("/GS1 gs\n/GS1 gs\n/GS2 gs\n/GS3 gs\n/GS3 gs\n", self::stream($pdf, self::contents($pdf)));
        $this->assertStringContainsString("1 0 obj\n<< /Type /ExtGState /ca 0.5 /CA 1 >>\nendobj", $pdf);
        $this->assertStringContainsString("2 0 obj\n<< /Type /ExtGState /ca 1 /CA 0.25 >>\nendobj", $pdf);
        $this->assertStringContainsString("3 0 obj\n<< /Type /ExtGState /ca 1 /CA 0 >>\nendobj", $pdf);
        $this->assertSame(3, \substr_count($pdf, needle: '/Type /ExtGState'));
        $this->assertStringContainsString('/ExtGState << /GS1 1 0 R /GS2 2 0 R /GS3 3 0 R >>', $pdf);
    }

    public function testAxialShadingsBlendBetweenTwoColors(): void
    {
        [$document, $canvas] = self::canvas();
        $canvas->axial([1.0, 0.0, 0.0], [0.0, 0.0, 1.0], [2.0, 0.0, 10.5, 0.0]);
        $canvas->axial([0.0, 0.0, 0.0], [1.0, 1.0, 1.0], [0.0, 1.0, 0.0, 2.0]);
        $pdf = self::pdf($document, $canvas);
        $this->assertSame("/Sh1 sh\n/Sh2 sh\n", self::stream($pdf, self::contents($pdf)));
        $this->assertStringContainsString(
            "1 0 obj\n<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [2 0 10.5 0] /Function << /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [0 0 1] /N 1 >> /Extend [true true] >>\nendobj",
            $pdf,
        );
        $this->assertStringContainsString('/Coords [0 1 0 2] /Function << /FunctionType 2 /Domain [0 1] /C0 [0 0 0] /C1 [1 1 1]', $pdf);
        $this->assertStringContainsString('/Shading << /Sh1 1 0 R /Sh2 2 0 R >>', $pdf);
    }

    public function testTextNamesEachFontOnceAndWritesItWithThePage(): void
    {
        [$document, $canvas] = self::canvas();
        $regular = new PdfFont(TrueTypeFont::file(MermaidFont::FILE) ?? throw new \LogicException('The bundled face is missing'));
        $synthetic = new PdfFont(TrueTypeFont::parse(TrueTypeFontTest::font([[0x41, 0x41, 1]])) ?? throw new \LogicException('The synthetic face is missing'));
        $canvas->text($regular, [0x41], [1.6, 0.0, 0.0, -1.6, 2.0, 3.0]);
        $canvas->text($synthetic, [0x41, 0x41], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]);
        $canvas->text($regular, [0x42], [1.6, 0.0, 0.0, -1.6, 2.0, 5.0]);
        $pdf = self::pdf($document, $canvas);
        $this->assertSame(
            "BT /F1 1 Tf 1.6 0 0 -1.6 2 3 Tm <001E> Tj ET\nBT /F2 1 Tf 1 0 0 1 0 0 Tm <00010001> Tj ET\nBT /F1 1 Tf 1.6 0 0 -1.6 2 5 Tm <001F> Tj ET\n",
            self::stream($pdf, self::contents($pdf)),
        );
        $this->assertStringContainsString('/Font << /F1 5 0 R /F2 10 0 R >>', $pdf);
        $this->assertStringContainsString("5 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /IBMPlexMono-Regular ", $pdf);
        $this->assertStringContainsString("10 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /Synthetic-Face ", $pdf);
        $this->assertStringContainsString('/W [30 [600] 31 [600]]', $pdf, 'widths are written once every glyph is known');
    }

    public function testImagesAreXObjectsDrawnOverTheUnitSquare(): void
    {
        $document = new PdfDocument(compress: true);
        $canvas = new PdfCanvas($document);
        $canvas->image(new PdfImage(2, 1, 'DeviceRGB', "\x01\x02\x03\x04\x05\x06", alpha: "\xFF\x80"), [6.0, 0.0, 0.0, -3.0, 30.0, 19.0]);
        $canvas->image(new PdfImage(3, 2, 'DeviceGray', 'JPEGDATA', jpeg: true), [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]);
        $pdf = self::pdf($document, $canvas);
        $mask = (string) \gzcompress("\xFF\x80");
        $rgb = (string) \gzcompress("\x01\x02\x03\x04\x05\x06");
        $this->assertStringContainsString(
            "1 0 obj\n<< /Type /XObject /Subtype /Image /Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /FlateDecode /Length ".\strlen($mask)." >>\nstream\n".$mask,
            $pdf,
        );
        $this->assertStringContainsString(
            "2 0 obj\n<< /Type /XObject /Subtype /Image /Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 1 0 R /Filter /FlateDecode /Length ".\strlen($rgb)." >>\nstream\n".$rgb,
            $pdf,
        );
        $this->assertStringContainsString(
            "3 0 obj\n<< /Type /XObject /Subtype /Image /Width 3 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /DCTDecode /Length 8 >>\nstream\nJPEGDATA\nendstream",
            $pdf,
        );
        $this->assertStringContainsString('/XObject << /Im1 2 0 R /Im2 3 0 R >>', $pdf);
        $this->assertSame("q\n6 0 0 -3 30 19 cm\n/Im1 Do\nQ\nq\n1 0 0 1 0 0 cm\n/Im2 Do\nQ\n", self::stream($pdf, self::contents($pdf)));
    }

    /** @return array{PdfDocument, PdfCanvas} */
    private static function canvas(): array
    {
        $document = new PdfDocument(compress: false);

        return [$document, new PdfCanvas($document)];
    }

    private static function content(PdfDocument $document, PdfCanvas $canvas): string
    {
        $pdf = self::pdf($document, $canvas);

        return self::stream($pdf, self::contents($pdf));
    }

    private static function pdf(PdfDocument $document, PdfCanvas $canvas): string
    {
        return $document->bytes($canvas->page(100.0, 50.0), producer: 'p');
    }

    private static function contents(string $pdf): int
    {
        $match = [];

        return \preg_match('/\/Contents (\d+) 0 R/', $pdf, $match) ? (int) $match[1] : 0;
    }

    /** A stream object's data, inflated when it is deflated. */
    public static function stream(string $pdf, int $id): string
    {
        $match = [];
        if (1 !== \preg_match('/(?:^|\n)'.$id.' 0 obj\n<<(.*?)>>\nstream\n(.*?)\nendstream\nendobj/s', $pdf, $match)) {
            return '';
        }

        return \str_contains($match[1], '/FlateDecode') ? (string) \gzuncompress($match[2]) : $match[2];
    }
}
