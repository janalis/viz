<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Render\Work;

/** Mermaid drawn in PHP, with no browser and no Node: SVG always, PNG with GD, PDF always. */
final readonly class MermaidRenderer implements Renderer
{
    public function __construct(
        private MermaidStyleSource $styles = new MermaidStyleSource(),
    ) {}

    #[\Override]
    public function supports(Format $format): bool
    {
        return Format::Png !== $format || \function_exists('imagettftext');
    }

    #[\Override]
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        $pipeline = new MermaidPipeline($this->styles, new MermaidPng($options->maxPixels));
        try {
            return new Image(Work::run($pipeline->export($source, $format, $options->dpi ?? MermaidPng::BASE_DPI)), $format);
        } catch (MermaidSyntaxError|\InvalidArgumentException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
    }
}
