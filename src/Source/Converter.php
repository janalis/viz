<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotRenderer;
use Janalis\Viz\GraphViz\DotWriter;
use Janalis\Viz\GraphViz\GraphParseException;
use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\PlantUML\PlantUmlWriter;
use Janalis\Viz\Render\RenderFailure;

/**
 * Moves a diagram between the three languages through the shared {@see Graph}.
 * A Mermaid flowchart is read natively; DOT is read through `dot -Tjson0`, which
 * resolves its whole grammar; PlantUML has no reader yet.
 */
final readonly class Converter
{
    public function __construct(
        private DotRenderer $dot = new DotRenderer(),
    ) {}

    /** @throws UnsupportedConversion|RenderFailure */
    public function convert(string $source, SourceFormat $from, SourceFormat $to): string
    {
        return self::write($this->read($source, $from), $to);
    }

    /** @throws UnsupportedConversion|RenderFailure */
    public function read(string $source, SourceFormat $format): Graph
    {
        return match ($format) {
            SourceFormat::Mermaid => self::mermaid($source),
            SourceFormat::Dot => $this->dot($source),
            SourceFormat::PlantUml => throw new UnsupportedConversion('PlantUML cannot be read yet: only written'),
        };
    }

    public static function write(Graph $graph, SourceFormat $format): string
    {
        return match ($format) {
            SourceFormat::Mermaid => MermaidWriter::write($graph),
            SourceFormat::Dot => DotWriter::write($graph),
            SourceFormat::PlantUml => PlantUmlWriter::write($graph),
        };
    }

    /** @throws RenderFailure when dot is missing or fails */
    private function dot(string $source): Graph
    {
        try {
            return DotReader::read($this->dot->json($source));
        } catch (GraphParseException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
    }

    /** @throws UnsupportedConversion when the source is not a flowchart */
    private static function mermaid(string $source): Graph
    {
        try {
            return MermaidReader::read($source);
        } catch (MermaidSyntaxError|\InvalidArgumentException $exception) {
            throw new UnsupportedConversion('Only Mermaid flowcharts convert: '.$exception->getMessage(), previous: $exception);
        }
    }
}
