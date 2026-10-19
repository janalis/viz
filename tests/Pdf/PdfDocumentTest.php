<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Pdf\PdfDocument;
use PHPUnit\Framework\TestCase;

/** Object numbering, streams, the cross-reference table and PDF's string and number syntax. */
final class PdfDocumentTest extends TestCase
{
    public function testObjectsAreNumberedInOrderAndReservedOnesDefinedLater(): void
    {
        $document = new PdfDocument(compress: false);
        $this->assertSame(1, $document->add('<< /A 1 >>'));
        $this->assertSame(2, $document->reserve());
        $this->assertSame(3, $document->add('[3]'));
        $document->define(2, '(two)');
        $this->assertSame(4, $document->reserve());
        $pdf = $document->bytes(1, producer: 'janalis/viz');
        $this->assertStringContainsString("1 0 obj\n<< /A 1 >>\nendobj\n2 0 obj\n(two)\nendobj\n3 0 obj\n[3]\nendobj\n4 0 obj\nnull\nendobj\n", $pdf);
        $this->assertStringContainsString("5 0 obj\n<< /Producer (janalis/viz) >>\nendobj\n", $pdf);
    }

    public function testTheCrossReferenceTablePointsAtEveryObject(): void
    {
        $document = new PdfDocument(compress: false);
        $document->add('<< /Type /Catalog >>');
        $document->stream('/Kind /Test', "some\nbytes");
        $document->add(\str_repeat('x', times: 12_345));
        $pdf = $document->bytes(1, producer: 'janalis/viz');
        $this->assertStringStartsWith("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n", $pdf);
        $this->assertStringEndsWith("\n%%EOF\n", $pdf);
        [$start, $entries] = [[], []];
        $this->assertSame(1, \preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $start));
        $xref = (int) $start[1];
        $this->assertSame("xref\n0 5\n0000000000 65535 f \n", \substr($pdf, $xref, length: 29));
        $this->assertSame(4, \preg_match_all('/^(\d{10}) 00000 n $/m', \substr($pdf, $xref), $entries));
        foreach ($entries[1] as $index => $offset) {
            $object = \sprintf('%d 0 obj', (int) $index + 1);
            $this->assertSame($object, \substr($pdf, (int) $offset, \strlen($object)));
        }
        $this->assertStringContainsString("trailer\n<< /Size 5 /Root 1 0 R /Info 4 0 R >>\nstartxref\n", $pdf);
    }

    public function testStreamsAreDeflatedUnlessUncompressibleOrDisabled(): void
    {
        $data = \str_repeat('compress me ', times: 50);
        $deflating = new PdfDocument(compress: true);
        $this->assertTrue($deflating->compress);
        $deflating->stream('/Length1 3', $data);
        $deflating->encoded('/Filter /DCTDecode', 'jpeg');
        $pdf = $deflating->bytes(1, producer: 'p');
        $compressed = (string) \gzcompress($data);
        $this->assertStringContainsString("1 0 obj\n<< /Length1 3 /Filter /FlateDecode /Length ".\strlen($compressed)." >>\nstream\n".$compressed."\nendstream\nendobj", $pdf);
        $this->assertStringContainsString("2 0 obj\n<< /Filter /DCTDecode /Length 4 >>\nstream\njpeg\nendstream\nendobj", $pdf);

        $plain = new PdfDocument(compress: false);
        $this->assertFalse($plain->compress);
        $plain->stream('', 'abc');
        $this->assertStringContainsString("1 0 obj\n<< /Length 3 >>\nstream\nabc\nendstream\nendobj", $plain->bytes(1, producer: 'p'));

        $this->assertSame(\function_exists('gzcompress'), new PdfDocument()->compress);
    }

    public function testLiteralStringsEscapeDelimitersAndNonPrintableBytes(): void
    {
        $this->assertSame('(plain text)', PdfDocument::literal('plain text'));
        $this->assertSame('(a\\(b\\)c\\\\d)', PdfDocument::literal('a(b)c\\d'));
        $this->assertSame('(\\012\\000\\037~\\177\\303\\251)', PdfDocument::literal("\n\x00\x1F~\x7F\u{E9}"));
        $this->assertSame('( )', PdfDocument::literal(' '));
        $this->assertSame('()', PdfDocument::literal(''));
    }

    public function testNumbersHaveFourDecimalsAndNoExponent(): void
    {
        $this->assertSame('0', PdfDocument::number(0.0));
        $this->assertSame('12', PdfDocument::number(12.0));
        $this->assertSame('-3.5', PdfDocument::number(-3.5));
        $this->assertSame('1.2346', PdfDocument::number(1.234_56));
        $this->assertSame('0.0001', PdfDocument::number(0.000_1));
        $this->assertSame('0', PdfDocument::number(-0.000_01), 'no negative zero');
        $this->assertSame('0', PdfDocument::number(-0.0));
        $this->assertSame('100000000000', PdfDocument::number(1.0e11));
        $this->assertSame('10', PdfDocument::number(10.0), 'trailing zeros only after the point');
        $this->assertSame('0', PdfDocument::number(\NAN));
        $this->assertSame('0', PdfDocument::number(\INF));
    }
}
