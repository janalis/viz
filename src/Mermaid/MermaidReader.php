<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Mermaid\Source\MermaidClassReader;
use Janalis\Viz\Mermaid\Source\MermaidEntityReader;
use Janalis\Viz\Mermaid\Source\MermaidGraphReader;
use Janalis\Viz\Mermaid\Source\MermaidSequenceReader;
use Janalis\Viz\Mermaid\Source\MermaidStateReader;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Source\Reader;
use Janalis\Viz\Source\UnsupportedConversion;

/** Mermaid read into the shared models, by the family its header declares. */
final readonly class MermaidReader implements Reader
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph, Family::State, Family::Classes, Family::Entity, Family::Sequence];
    }

    /**
     * @throws MermaidSyntaxError when the source does not parse
     * @throws \InvalidArgumentException when its configuration cannot be applied
     * @throws UnsupportedConversion when its family has no shared model
     */
    #[\Override]
    public function read(string $source): Diagram
    {
        $kind = MermaidKinds::detect($source);

        return match ($kind) {
            'flowchart' => MermaidGraphReader::read($source),
            'state' => MermaidStateReader::read($source),
            'class' => MermaidClassReader::read($source),
            'er' => MermaidEntityReader::read($source),
            'sequence' => MermaidSequenceReader::read($source),
            default => throw new UnsupportedConversion('A Mermaid '.$kind.' diagram cannot be read yet'),
        };
    }
}
