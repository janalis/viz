<?php

declare(strict_types=1);

namespace Janalis\Viz;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\GraphViz\DotCommand;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Mermaid\MermaidCommand;
use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\PlantUML\PlantUmlCommand;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\CommandRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Engine;
use Janalis\Viz\Render\Exporter;
use Janalis\Viz\Render\FallbackRenderer;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Source\Converter;
use Janalis\Viz\Source\SourceFormat;
use Janalis\Viz\Source\UnsupportedConversion;

/**
 * The library in one place: render, export and convert any of the three
 * languages. Each language is drawn natively where a native renderer exists,
 * with its command-line tool as the fallback; the engine can also force one or
 * the other. A tool path left null is looked up on the PATH when first needed.
 */
final readonly class Viz
{
    public function __construct(
        private ?string $dot = null,
        private ?string $plantuml = null,
        private ?string $mmdc = null,
        private ?string $workingDirectory = null,
        private Engine $engine = Engine::Auto,
    ) {}

    public function renderer(SourceFormat $format): Renderer
    {
        return new FallbackRenderer(match ($this->engine) {
            Engine::Auto => [$this->native($format), $this->cli($format)],
            Engine::Native => [$this->native($format)],
            Engine::Cli => [$this->cli($format)],
        });
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
        return new Converter()->convert($source, $from, $to);
    }

    private function native(SourceFormat $format): Renderer
    {
        return match ($format) {
            SourceFormat::Mermaid => new NativeRenderer(new MermaidPipeline()),
            SourceFormat::Dot => new NativeRenderer(new DotPipeline()),
            SourceFormat::PlantUml => new NativeRenderer(PlantUmlPipeline::native()),
        };
    }

    private function cli(SourceFormat $format): Renderer
    {
        [$command, $tool] = match ($format) {
            SourceFormat::Mermaid => [new MermaidCommand(), new Tool('mmdc', $this->mmdc)],
            SourceFormat::Dot => [new DotCommand(), new Tool('dot', $this->dot)],
            SourceFormat::PlantUml => [new PlantUmlCommand(), new Tool('plantuml', $this->plantuml)],
        };

        return new CommandRenderer($command, $tool, new Cli($this->workingDirectory));
    }
}
