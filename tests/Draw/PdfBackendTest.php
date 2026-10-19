<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PdfBackend;
use Janalis\Viz\Tests\Pdf\PdfCanvasTest;
use Janalis\Viz\Tests\Pdf\PdfImageTest;
use Janalis\Viz\Tests\Pdf\TrueTypeFontTest;
use Janalis\Viz\Text\FallbackFonts;
use Janalis\Viz\Text\Font;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** The native PDF backend: page geometry, marks as PDF paint, embedded searchable text. */
final class PdfBackendTest extends TestCase
{
    private const string TRANSFORM = "7.5 0 0 -7.5 0 150 cm\n";

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            \unlink($file);
        }
    }

    public function testThePageIsTheDrawingSizeUnderOneFlippedTransform(): void
    {
        $pdf = self::render(new Drawing(40.0, 20.0, []));
        $this->assertStringContainsString('/MediaBox [0 0 300 150]', $pdf);
        $this->assertSame(self::TRANSFORM, self::content($pdf));
        $this->assertStringContainsString('/MediaBox [0 0 7.5 9.375]', self::render(new Drawing(1.0, 1.25, [])));
        $this->assertStringContainsString('/MediaBox [0 0 9.375 7.5]', self::render(new Drawing(1.25, 1.0, [])));
    }

    public function testTheCrossReferenceTableIsExactAndTheOutputDeterministic(): void
    {
        $drawing = new Drawing(40.0, 20.0, [new Mark(MarkKind::Text, [[10.0, 5.0]], 'Hi'), new Mark(MarkKind::Polygon, [[1.0, 1.0], [2.0, 1.0], [2.0, 2.0]], fillOpacity: 0.5)]);
        $pdf = self::render($drawing, new PdfBackend(fallbacks: new FallbackFonts([])));
        $this->assertSame($pdf, self::render($drawing, new PdfBackend(fallbacks: new FallbackFonts([]))));
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        [$start, $xref] = [[], []];
        $this->assertSame(1, \preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $start));
        $this->assertSame(1, \preg_match(
            '/^xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)trailer\n<< \/Size (\d+) \/Root (\d+) 0 R \/Info (\d+) 0 R >>/',
            \substr($pdf, (int) $start[1]),
            $xref,
        ));
        $this->assertSame($xref[1], $xref[3]);
        $offsets = \explode("\n", \trim($xref[2]));
        $this->assertCount((int) $xref[1] - 1, $offsets);
        foreach ($offsets as $index => $entry) {
            $object = ($index + 1).' 0 obj';
            $this->assertSame($object, \substr($pdf, (int) \substr($entry, offset: 0, length: 10), \strlen($object)));
        }
        $this->assertStringContainsString("\n".$xref[4]." 0 obj\n<< /Type /Catalog ", $pdf);
        $this->assertStringContainsString("\n".$xref[5]." 0 obj\n<< /Producer (janalis/viz) >>", $pdf);
        $this->assertStringNotContainsString('CreationDate', $pdf);
        $this->assertSame(\function_exists('gzcompress'), \str_contains($pdf, '/FlateDecode'));
        $this->assertSame(self::TRANSFORM, \substr(self::content($pdf), offset: 0, length: \strlen(self::TRANSFORM)), 'deflated content inflates back');
    }

    public function testStreamsAreLeftPlainWhenCompressionIsOff(): void
    {
        $this->assertStringNotContainsString('/FlateDecode', self::render(new Drawing(4.0, 2.0, [new Mark(MarkKind::Text, [[1.0, 1.0]], 'A')])));
    }

    /** @return iterable<string, array{float, float}> */
    public static function invalidSizes(): iterable
    {
        yield 'narrow' => [0.5, 10.0];
        yield 'short' => [10.0, 0.0];
        yield 'not a number' => [\NAN, 10.0];
        yield 'infinite' => [10.0, \INF];
    }

    #[DataProvider('invalidSizes')]
    public function testDimensionsMustBeFiniteAndAtLeastOneUnit(float $width, float $height): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Invalid PDF dimensions$/');
        self::render(new Drawing($width, $height, []));
    }

    public function testTheBackgroundFillsThePage(): void
    {
        $this->assertSame(self::TRANSFORM."q\n1 1 0.9333 rg\n0 0 m\n40 0 l\n40 20 l\n0 20 l\nh\nf\nQ\n", self::content(self::render(new Drawing(40.0, 20.0, [], '#ffffee'))));
        $pdf = self::render(new Drawing(40.0, 20.0, [], '#0000ff80'));
        $this->assertSame(self::TRANSFORM."q\n/GS1 gs\n0 0 1 rg\n0 0 m\n40 0 l\n40 20 l\n0 20 l\nh\nf\nQ\n", self::content($pdf));
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.502 /CA 1 >>', $pdf);
    }

    public function testPolygonsFillAndStrokeAndPolylinesOnlyStroke(): void
    {
        $triangle = [[1.0, 1.0], [3.0, 1.0], [3.0, 2.0]];
        $this->assertSame(
            "q\n0 1 0 rg\n1 0 0 RG\n0.2 w\n1 1 m\n3 1 l\n3 2 l\nh\nB\nQ\n",
            self::marks(new Mark(MarkKind::Polygon, $triangle, stroke: '#ff0000', fill: '#00ff00', strokeWidth: 0.2)),
        );
        $this->assertSame("q\n1 1 1 rg\n0.2 0.2 0.2 RG\n0.12 w\n1 1 m\n3 1 l\n3 2 l\nS\nQ\n", self::marks(new Mark(MarkKind::Polyline, $triangle)));
        $this->assertSame("q\n0.2 0.2 0.2 rg\n0.2 0.2 0.2 RG\n0.12 w\n1 1 m\n3 1 l\n3 2 l\nh\nS\nQ\n", self::marks(new Mark(MarkKind::Polygon, $triangle, fill: 'none')));
        $this->assertSame("q\n1 0 0 rg\n0.2 0.2 0.2 RG\n0 w\n1 1 m\n3 1 l\n3 2 l\nh\nf\nQ\n", self::marks(new Mark(MarkKind::Polygon, $triangle, fill: 'red', strokeWidth: 0.0)));
        $this->assertSame("q\n0.2 0.2 0.2 rg\n0.2 0.2 0.2 RG\n0.12 w\n1 1 m\n3 1 l\n3 2 l\nh\nf\nQ\n", self::marks(new Mark(MarkKind::Polygon, $triangle, stroke: 'none', fill: 'bogus')));
        $this->assertSame('', self::marks(new Mark(MarkKind::Polygon, $triangle, stroke: 'none', fill: 'none')));
        $this->assertSame('', self::marks(new Mark(MarkKind::Polyline, $triangle, strokeWidth: -1.0)));
        $this->assertSame('', self::marks(new Mark(MarkKind::Polygon, [])));
    }

    public function testDashedStrokesUseTheAuthoredOrDefaultPattern(): void
    {
        $line = [[0.0, 0.0], [5.0, 0.0]];
        $this->assertStringContainsString("0.12 w\n[0.6 0.4] 0 d\n0 0 m\n", self::marks(new Mark(MarkKind::Polyline, $line, dashed: true)));
        $this->assertStringContainsString("[1 0.5 0.25] 0 d\n", self::marks(new Mark(MarkKind::Polyline, $line, dashed: true, dashes: [1.0, 0.5, 0.25])));
        $this->assertStringNotContainsString(' d', self::marks(new Mark(MarkKind::Polyline, $line, dashes: [1.0, 0.5])));
    }

    public function testOpacityBecomesSharedTransparencyStates(): void
    {
        $square = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]];
        $pdf = self::render(new Drawing(40.0, 20.0, [
            new Mark(MarkKind::Polygon, $square, fillOpacity: 0.5),
            new Mark(MarkKind::Polygon, $square, fillOpacity: 0.5),
            new Mark(MarkKind::Polygon, $square, stroke: '#00000040', fill: '#00ff0080', fillOpacity: 0.5, opacity: 0.5),
        ]));
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.5 /CA 1 >>', $pdf);
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.1255 /CA 0.1255 >>', $pdf);
        $this->assertSame(2, \substr_count($pdf, needle: '/Type /ExtGState'));
        $this->assertSame(2, \substr_count(self::content($pdf), needle: '/GS1 gs'));
        $this->assertSame(1, \substr_count(self::content($pdf), needle: '/GS2 gs'));
        $this->assertSame(
            "q\n1 1 1 rg\n0 0 0 RG\n0 w\n0 0 m\n1 0 l\n1 1 l\nh\nf\nQ\n",
            self::marks(new Mark(MarkKind::Polygon, $square, stroke: '#00000040', strokeWidth: 0.0)),
            'an unstroked outline ignores the stroke alpha',
        );
        $this->assertSame(
            "q\n1 1 1 rg\n0 0 0 RG\n0.12 w\n0 0 m\n1 0 l\n1 1 l\nS\nQ\n",
            self::marks(new Mark(MarkKind::Polyline, $square, stroke: '#000000', fillOpacity: 0.5)),
            'an unfilled one the fill opacity',
        );
    }

    public function testGradientsShadeAcrossThePolygonClippedToIt(): void
    {
        $pdf = self::render(new Drawing(40.0, 20.0, [new Mark(
            MarkKind::Polygon,
            [[2.0, 2.0], [10.0, 2.0], [10.0, 4.0], [2.0, 4.0]],
            strokeWidth: 0.2,
            fillOpacity: 0.5,
            opacity: 0.8,
            gradient: ['#ff000080', '#0000ff'],
        )]));
        $this->assertSame(
            self::TRANSFORM."q\n2 2 m\n10 2 l\n10 4 l\n2 4 l\nh\nW n\n/GS1 gs\n/Sh1 sh\nQ\nq\n/GS2 gs\n1 1 1 rg\n0.2 0.2 0.2 RG\n0.2 w\n2 2 m\n10 2 l\n10 4 l\n2 4 l\nh\nS\nQ\n",
            self::content($pdf),
        );
        $this->assertStringContainsString('/Coords [2 0 10 0] /Function << /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [0 0 1] /N 1 >>', $pdf);
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.2008 /CA 1 >>', $pdf);
        $this->assertStringContainsString('<< /Type /ExtGState /ca 1 /CA 0.8 >>', $pdf);

        $sliver = self::render(new Drawing(40.0, 20.0, [new Mark(MarkKind::Polygon, [[5.0, 1.0], [5.0, 3.0]], strokeWidth: 0.0, gradient: ['#ffffff', '#000000'])]));
        $this->assertStringContainsString('/Coords [5 0 5.001 0]', $sliver);
        $this->assertSame(self::TRANSFORM."q\n5 1 m\n5 3 l\nh\nW n\n/Sh1 sh\nQ\n", self::content($sliver));
        $this->assertSame('', self::marks(new Mark(MarkKind::Polygon, [], gradient: ['#ffffff', '#000000'])));
        $this->assertStringNotContainsString('sh', self::marks(new Mark(MarkKind::Polyline, [[0.0, 0.0], [1.0, 1.0]], gradient: ['#ffffff', '#000000'])));
    }

    public function testTextIsCenteredOnItsAnchorByTheFaceAdvances(): void
    {
        // IBM Plex Mono advances 600 of 1000 units; text is 1.6 units tall, so "AB" spans 1.92.
        $this->assertSame("q\n0 0 0 rg\nBT /F1 1 Tf 1.6 0 0 -1.6 9.04 5 Tm <001E001F> Tj ET\nQ\n", self::marks(new Mark(MarkKind::Text, [[10.0, 5.0]], 'AB', stroke: '#000000')));
        $this->assertSame("q\n0.2 0.2 0.2 rg\nBT /F1 1 Tf 3.2 0 0 -3.2 9.04 5 Tm <001E> Tj ET\nQ\n", self::marks(new Mark(MarkKind::Text, [[10.0, 5.0]], 'A', fontScale: 2.0)));
    }

    public function testTurnedTextRotatesClockwiseAboutItsAnchor(): void
    {
        $this->assertStringContainsString('BT /F1 1 Tf 0 1.6 1.6 0 10 4.04 Tm', self::marks(new Mark(MarkKind::Text, [[10.0, 5.0]], 'AB', angle: 90.0)));
        $this->assertStringContainsString('BT /F1 1 Tf 1.3856 0.8 0.8 -1.3856 9.1686 4.52 Tm', self::marks(new Mark(MarkKind::Text, [[10.0, 5.0]], 'AB', angle: 30.0)));
    }

    public function testColorsReadOnlyAWholeEightDigitHexAsAlpha(): void
    {
        $this->assertStringStartsWith("q\n/GS1 gs\n1 0 0 rg\n", self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], 'A', stroke: '#FF000080')), 'hex digits in either case');
        $this->assertStringStartsWith("q\n0.2 0.2 0.2 rg\nBT", self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], 'A', stroke: '#ff00008000')));
        $this->assertStringStartsWith("q\n0.2 0.2 0.2 rg\nBT", self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], 'A', stroke: 'x#ff000080')));
    }

    public function testTextTakesTheMarkOpacityAndSkipsWhatCannotDraw(): void
    {
        $pdf = self::render(new Drawing(40.0, 20.0, [new Mark(MarkKind::Text, [[1.0, 1.0]], 'A', stroke: '#ff000080', opacity: 0.5)]));
        $this->assertStringStartsWith(self::TRANSFORM."q\n/GS1 gs\n1 0 0 rg\nBT ", self::content($pdf));
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.251 /CA 1 >>', $pdf);
        $this->assertSame('', self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], '')));
        $this->assertSame('', self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], "\x01\x1F")));
        $this->assertSame('', self::marks(new Mark(MarkKind::Text, [], 'A')));
        $this->assertStringContainsString("Tm <001E> Tj ET\n", self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], "A\x08")));
        $this->assertStringContainsString("Tm <001E005F> Tj ET\n", self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], "A\xFF")), 'invalid UTF-8 draws a question mark');
    }

    public function testEachUsedFaceIsEmbeddedOnceWithItsTextMap(): void
    {
        $pdf = self::render(new Drawing(40.0, 20.0, [
            new Mark(MarkKind::Text, [[1.0, 1.0]], 'AB'),
            new Mark(MarkKind::Text, [[1.0, 3.0]], 'é'),
            new Mark(MarkKind::Text, [[1.0, 5.0]], 'A', font: FontFace::BoldItalic),
        ]));
        $this->assertSame(2, \substr_count($pdf, needle: '/Subtype /Type0'));
        $this->assertSame(2, \substr_count($pdf, needle: '/FontFile2'));
        $this->assertStringContainsString('/BaseFont /IBMPlexMono-Regular /Encoding /Identity-H', $pdf);
        $this->assertStringContainsString('/BaseFont /IBMPlexMono-BoldItalic /Encoding /Identity-H', $pdf);
        $this->assertStringContainsString('/Flags 97 ', $pdf, 'the italic face says so');
        $this->assertStringContainsString('/Length1 '.\strlen((string) \file_get_contents(Font::FILE)).' ', $pdf);
        $map = [];
        $this->assertSame(1, \preg_match('/\/ToUnicode (\d+) 0 R/', $pdf, $map));
        $this->assertStringContainsString("<001E> <0041>\n<001F> <0042>\n<00E9> <00E9>\n", PdfCanvasTest::stream($pdf, (int) $map[1]));
        $this->assertStringContainsString('/W [30 [600] 31 [600] 233 [600]]', $pdf);
    }

    public function testCharactersTheFaceLacksComeFromAnEmbeddableFallback(): void
    {
        $fallback = $this->file(TrueTypeFontTest::font([[0x6F22, 0x6F22, 1]], advances: [500, 1024]));
        $pdf = self::render(new Drawing(40.0, 20.0, [new Mark(
            MarkKind::Text,
            [[10.0, 5.0]],
            'A漢B',
            stroke: '#000000',
        )]), new PdfBackend(fallbacks: new FallbackFonts([$fallback]), compress: false));
        // 0.6 + 0.5 + 0.6 ems at 1.6 units: 2.72 wide, so the runs start at 8.64, 9.6 and 10.4.
        $this->assertSame(
            self::TRANSFORM
            ."q\n0 0 0 rg\n"
            ."BT /F1 1 Tf 1.6 0 0 -1.6 8.64 5 Tm <001E> Tj ET\n"
            ."BT /F2 1 Tf 1.6 0 0 -1.6 9.6 5 Tm <0001> Tj ET\n"
            ."BT /F1 1 Tf 1.6 0 0 -1.6 10.4 5 Tm <001F> Tj ET\n"
            ."Q\n",
            self::content($pdf),
        );
        $this->assertStringContainsString('/BaseFont /Synthetic-Face /Encoding /Identity-H', $pdf);
        $this->assertStringContainsString("<0001> <6F22>\n", $pdf);
    }

    public function testTurnedRunsAdvanceAlongTheTurnedBaseline(): void
    {
        $fallback = $this->file(TrueTypeFontTest::font([[0x6F22, 0x6F22, 1]], advances: [500, 1024]));
        $pdf = self::render(new Drawing(40.0, 20.0, [new Mark(
            MarkKind::Text,
            [[10.0, 5.0]],
            'A漢B',
            angle: 90.0,
        )]), new PdfBackend(fallbacks: new FallbackFonts([$fallback]), compress: false));
        $content = self::content($pdf);
        $this->assertStringContainsString('BT /F1 1 Tf 0 1.6 1.6 0 10 3.64 Tm <001E> Tj ET', $content);
        $this->assertStringContainsString('BT /F2 1 Tf 0 1.6 1.6 0 10 4.6 Tm <0001> Tj ET', $content);
        $this->assertStringContainsString('BT /F1 1 Tf 0 1.6 1.6 0 10 5.4 Tm <001F> Tj ET', $content);
    }

    public function testFallbacksThatCannotBeEmbeddedLeaveTheMissingGlyph(): void
    {
        $collection = $this->file(TrueTypeFontTest::sfnt(TrueTypeFontTest::tables([[0x6F22, 0x6F22, 1]]), version: 'OTTO'));
        $pdf = self::render(new Drawing(40.0, 20.0, [new Mark(MarkKind::Text, [[10.0, 5.0]], 'A漢')]), new PdfBackend(fallbacks: new FallbackFonts([$collection]), compress: false));
        $this->assertStringContainsString('1.6 0 0 -1.6 9.04 5 Tm <001E0000> Tj ET', self::content($pdf));
        $this->assertSame(1, \substr_count($pdf, needle: '/Subtype /Type0'));
        $this->assertStringContainsString('Tm <0000> Tj', self::marks(new Mark(MarkKind::Text, [[1.0, 1.0]], '😎')));
    }

    public function testAMissingBundledFaceIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Cannot read the bundled font$/');
        self::render(new Drawing(4.0, 2.0, [new Mark(MarkKind::Text, [[1.0, 1.0]], 'A')]), new PdfBackend(font: '/nonexistent/IBMPlexMono-Regular.ttf', compress: false));
    }

    public function testImagesStretchToTheirBox(): void
    {
        $picture = \imagecreatetruecolor(2, height: 1);
        \imagealphablending($picture, enable: false);
        \imagesavealpha($picture, enable: true);
        \imagesetpixel($picture, x: 0, y: 0, color: 0x40_FF_00_00);
        $pdf = self::render(new Drawing(40.0, 20.0, [
            self::image(PdfImageTest::png($picture)),
            self::image(PdfImageTest::jpeg(), 'jpeg'),
        ]));
        $this->assertSame(self::TRANSFORM."q\n6 0 0 -3 30 19 cm\n/Im1 Do\nQ\nq\n6 0 0 -3 30 19 cm\n/Im2 Do\nQ\n", self::content($pdf));
        $this->assertStringContainsString('/Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask ', $pdf);
        $this->assertStringContainsString('/Width 5 /Height 3 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /DCTDecode ', $pdf);
        $this->assertStringContainsString(PdfImageTest::jpeg(), $pdf);
    }

    public function testImagesBeyondThePixelBudgetOrUndecodableAreSkipped(): void
    {
        // 5 × 3 pixels: the budget compares the area, not a side or the image type.
        $jpeg = self::image(PdfImageTest::jpeg());
        $png = self::image(PdfImageTest::png(\imagecreatetruecolor(3, height: 2)));
        $this->assertSame('', self::marks($jpeg, new PdfBackend(maxPixels: 14, compress: false)));
        $this->assertStringContainsString('/Im1 Do', self::marks($jpeg, new PdfBackend(maxPixels: 15, compress: false)));
        $this->assertSame('', self::marks($png, new PdfBackend(maxPixels: 5, compress: false)));
        $this->assertSame('', self::marks($png, new PdfBackend(gdAvailable: false, compress: false)), 'PNG needs GD');
        $this->assertStringContainsString('/Im1 Do', self::marks($jpeg, new PdfBackend(gdAvailable: false, compress: false)), 'JPEG does not');
        \error_clear_last();
        $this->assertSame('', self::marks(self::image(\substr(PdfImageTest::png(\imagecreatetruecolor(3, height: 2)), offset: 0, length: 40))), 'a header without its data');
        $this->assertNull(\error_get_last(), 'the decoder warning is swallowed');
        $this->assertSame('', self::marks(self::image('not an image')));
        $this->assertSame('', self::marks(new Mark(MarkKind::Image, [[0.0, 0.0], [1.0, 1.0]], 'data:image/png;base64,@@@')));
        $this->assertSame('', self::marks(new Mark(MarkKind::Image, [[0.0, 0.0], [1.0, 1.0]], 'no comma')));
        $this->assertSame(
            '',
            self::marks(new Mark(MarkKind::Image, [[0.0, 0.0], [1.0, 1.0]], 'data:image/jpeg;base64,'.\base64_encode(PdfImageTest::jpeg()).',trailing')),
            'everything after the first comma is the payload',
        );
        $this->assertSame('', self::marks(new Mark(MarkKind::Image, [[0.0, 0.0]], 'data:image/jpeg;base64,'.\base64_encode(PdfImageTest::jpeg()))));
    }

    public function testPopplerReadsTheFileAndItsText(): void
    {
        $finder = new ExecutableFinder();
        $pdftotext = $finder->find('pdftotext');
        $pdfinfo = $finder->find('pdfinfo');
        if (null === $pdftotext || null === $pdfinfo) {
            $this->markTestSkipped('Poppler (pdftotext, pdfinfo) is not installed');
        }
        $path = $this->file(self::render(
            new Drawing(
                40.0,
                20.0,
                [
                    new Mark(MarkKind::Polygon, [[1.0, 1.0], [39.0, 1.0], [39.0, 19.0], [1.0, 19.0]], dashed: true, fillOpacity: 0.5, gradient: ['#ff0000', '#0000ff']),
                    new Mark(MarkKind::Text, [[20.0, 8.0]], 'Hello é→'),
                    new Mark(MarkKind::Text, [[20.0, 14.0]], 'World', font: FontFace::Bold),
                    self::image(PdfImageTest::jpeg()),
                ],
                '#ffffff',
            ),
            new PdfBackend(fallbacks: new FallbackFonts([])),
        ));
        $text = new Process([$pdftotext, '-enc', 'UTF-8', $path, '-']);
        $text->mustRun();
        $this->assertSame('', $text->getErrorOutput(), 'Poppler reports no syntax error');
        $this->assertStringContainsString('Hello é→', $text->getOutput());
        $this->assertStringContainsString('World', $text->getOutput());
        $info = new Process([$pdfinfo, $path]);
        $info->mustRun();
        $this->assertSame('', $info->getErrorOutput());
        $this->assertMatchesRegularExpression('/^Page size:\s+300 x 150 pts$/m', $info->getOutput());
        $this->assertMatchesRegularExpression('/^Producer:\s+janalis\/viz$/m', $info->getOutput());
    }

    private static function image(string $bytes, string $type = 'png'): Mark
    {
        return new Mark(MarkKind::Image, [[30.0, 16.0], [36.0, 19.0]], 'data:image/'.$type.';base64,'.\base64_encode($bytes));
    }

    /** The content a single mark adds after the page transform. */
    private static function marks(Mark $mark, ?PdfBackend $renderer = null): string
    {
        return \substr(self::content(self::render(new Drawing(40.0, 20.0, [$mark]), $renderer)), \strlen(self::TRANSFORM));
    }

    private static function render(Drawing $drawing, ?PdfBackend $renderer = null): string
    {
        $work = ($renderer ?? new PdfBackend(fallbacks: new FallbackFonts([]), compress: false))->render($drawing);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    private static function content(string $pdf): string
    {
        $match = [];

        return PdfCanvasTest::stream($pdf, \preg_match('/\/Contents (\d+) 0 R/', $pdf, $match) ? (int) $match[1] : 0);
    }

    private function file(string $bytes): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), prefix: 'viz-pdf-');
        \file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
