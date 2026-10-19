<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\ParseError;

/** A language drawn in PHP, with no external tool: SVG always, PNG with GD and FreeType, PDF always. */
final readonly class NativeRenderer implements Renderer
{
    public function __construct(
        private Pipeline $pipeline,
    ) {}

    #[\Override]
    public function supports(Format $format): bool
    {
        return Format::Png !== $format || \function_exists('imagettftext');
    }

    #[\Override]
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        try {
            return new Image(Work::run($this->image($source, $format, $options->dpi ?? PngBackend::BASE_DPI, $options->maxPixels)), $format);
        } catch (ParseError|\InvalidArgumentException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The whole render as resumable steps, for a host with an event loop.
     *
     * @return \Generator<int, null, mixed, string>
     * @throws ParseError when the source does not parse
     * @throws \InvalidArgumentException when the source cannot be drawn in $format
     */
    public function image(string $source, Format $format, int $dpi = PngBackend::BASE_DPI, int $maxPixels = PngBackend::MAX_PIXELS): \Generator
    {
        $drawing = $this->pipeline->drawing($source);
        yield from $drawing;
        $image = new Encoder(new PngBackend($maxPixels))->encode($drawing->getReturn(), $format, $dpi);
        yield from $image;

        return $image->getReturn();
    }
}
