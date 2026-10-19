<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Render\Format;

/**
 * The native pipeline as resumable steps: source → scene → drawing → image. Each
 * step is a generator that yields between units of work, so a host with an event
 * loop can spread a large diagram over several frames ({@see \Janalis\Viz\Render\Work}
 * runs one to the end).
 */
final readonly class MermaidPipeline
{
    public function __construct(
        private MermaidStyleSource $styles = new MermaidStyleSource(),
        private MermaidPng $png = new MermaidPng(),
        private MermaidPdf $pdf = new MermaidPdf(),
    ) {}

    /**
     * @return \Generator<int, null, mixed, MermaidScene>
     * @throws MermaidSyntaxError when the source does not parse
     * @throws \InvalidArgumentException when the configuration cannot be applied
     */
    public function scene(string $source): \Generator
    {
        $frontMatter = MermaidFrontMatter::parse(MermaidSource::unmarked($source));
        $preparation = MermaidInitialization::parse($frontMatter->source, $frontMatter->configuration);
        yield from $preparation;
        $initialization = $preparation->getReturn();
        $diagram = new NativeMermaidDiagram($initialization->source, $initialization->configuration, $this->styles);
        $authoredTitle = false;
        foreach ($diagram->statements() as $statement) {
            $authoredTitle = $diagram->isTitle($statement) || $authoredTitle;
            $diagram->accept($statement);
            yield null;
        }
        $layout = $diagram->layout();
        yield from $layout;

        if (null !== $frontMatter->title && !$authoredTitle && $diagram->supportsTitle) {
            $margin = $initialization->configuration->flowchart?->spacing('titleTopMargin', default: 1.0) ?? 1.0;
            $caption = MermaidTitle::apply($layout->getReturn(), $frontMatter->title, $margin);
            yield from $caption;

            return $caption->getReturn()->themed($diagram->palette);
        }

        return $layout->getReturn()->themed($diagram->palette);
    }

    /**
     * @return \Generator<int, null, mixed, string>
     * @throws \InvalidArgumentException when the scene cannot be drawn in $format
     */
    public function image(MermaidScene $scene, Format $format, int $dpi = MermaidPng::BASE_DPI): \Generator
    {
        $drawing = MermaidDrawing::scene($scene);
        yield from $drawing;
        $image = match ($format) {
            Format::Svg => MermaidSvg::render($drawing->getReturn()),
            Format::Png => $this->png->render($drawing->getReturn(), $dpi),
            Format::Pdf => $this->pdf->render($drawing->getReturn()),
        };
        yield from $image;

        return $image->getReturn();
    }

    /**
     * @return \Generator<int, null, mixed, string>
     * @throws MermaidSyntaxError when the source does not parse
     * @throws \InvalidArgumentException when the diagram cannot be drawn in $format
     */
    public function export(string $source, Format $format, int $dpi = MermaidPng::BASE_DPI): \Generator
    {
        $scene = $this->scene($source);
        yield from $scene;
        $image = $this->image($scene->getReturn(), $format, $dpi);
        yield from $image;

        return $image->getReturn();
    }
}
