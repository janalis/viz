<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Render\Format;

/** Encodes one display list in an image format, yielding between marks. */
final readonly class Encoder
{
    public function __construct(
        private PngBackend $png = new PngBackend(),
        private PdfBackend $pdf = new PdfBackend(),
    ) {}

    /**
     * @return \Generator<int, null, mixed, string>
     * @throws \InvalidArgumentException when the drawing cannot be encoded in $format
     */
    public function encode(Drawing $drawing, Format $format, int $dpi = PngBackend::BASE_DPI): \Generator
    {
        $image = match ($format) {
            Format::Svg => SvgBackend::render($drawing),
            Format::Png => $this->png->render($drawing, $dpi),
            Format::Pdf => $this->pdf->render($drawing),
        };
        yield from $image;

        return $image->getReturn();
    }
}
