<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotWriter;
use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\PlantUML\PlantUmlReader;
use Janalis\Viz\PlantUML\PlantUmlWriter;

/** Each language's reader and writer; a language without a reader cannot be converted from. */
final readonly class Languages
{
    public function __construct(
        private Reader $mermaid = new MermaidReader(),
        private Reader $dot = new DotReader(),
        private Reader $plantUml = new PlantUmlReader(),
        private Writer $mermaidWriter = new MermaidWriter(),
        private Writer $dotWriter = new DotWriter(),
        private Writer $plantUmlWriter = new PlantUmlWriter(),
    ) {}

    /** @throws UnsupportedConversion when the language cannot be read */
    public function reader(SourceFormat $format): Reader
    {
        return match ($format) {
            SourceFormat::Mermaid => $this->mermaid,
            SourceFormat::Dot => $this->dot,
            SourceFormat::PlantUml => $this->plantUml,
        };
    }

    public function writer(SourceFormat $format): Writer
    {
        return match ($format) {
            SourceFormat::Mermaid => $this->mermaidWriter,
            SourceFormat::Dot => $this->dotWriter,
            SourceFormat::PlantUml => $this->plantUmlWriter,
        };
    }
}
