<?php

declare(strict_types=1);

namespace Janalis\Viz;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotRenderer;
use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MmdcRenderer;
use Janalis\Viz\PlantUML\PlantUmlRenderer;
use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Exporter;
use Janalis\Viz\Render\FallbackRenderer;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Source\Converter;
use Janalis\Viz\Source\Languages;
use Janalis\Viz\Source\SourceFormat;
use Janalis\Viz\Source\UnsupportedConversion;

/**
 * The library in one place: render, export and convert any of the three
 * languages. Each language is drawn natively where a native renderer exists
 * (Mermaid), and by its command-line tool otherwise or as the fallback. A tool
 * path left null is looked up on the PATH when first needed.
 */
final readonly class Viz
{
    public function __construct(
        private ?string $dot = null,
        private ?string $plantuml = null,
        private ?string $mmdc = null,
        private ?string $workingDirectory = null,
    ) {}

    public function renderer(SourceFormat $format): Renderer
    {
        $cli = new Cli($this->workingDirectory);

        return match ($format) {
            SourceFormat::Mermaid => new FallbackRenderer([new NativeRenderer(new MermaidPipeline()), new MmdcRenderer(new Tool('mmdc', $this->mmdc), $cli)]),
            SourceFormat::Dot => $this->dotRenderer(),
            SourceFormat::PlantUml => new PlantUmlRenderer(new Tool('plantuml', $this->plantuml), $cli),
        };
    }

    /** @throws RenderFailure */
    public function render(string $source, SourceFormat $language, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        return $this->renderer($language)->render($source, $format, $options);
    }

    /**
     * Writes the image to $path, only once it has rendered in full.
     *
     * @param ?Format $format null takes it from the path's extension
     * @throws RenderFailure
     */
    public function export(string $source, SourceFormat $language, string $path, ?Format $format = null, RenderOptions $options = new RenderOptions()): Image
    {
        return new Exporter($this->renderer($language))->export($source, $path, $format, $options);
    }

    /** @throws UnsupportedConversion|RenderFailure */
    public function convert(string $source, SourceFormat $from, SourceFormat $to): string
    {
        return new Converter(new Languages(dot: new DotReader($this->dotRenderer())))->convert($source, $from, $to);
    }

    private function dotRenderer(): DotRenderer
    {
        return new DotRenderer(new Tool('dot', $this->dot), new Cli($this->workingDirectory));
    }
}
