<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Mermaid\Source\MermaidGraphReader;
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
        return [Family::Graph, Family::State];
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
            default => throw new UnsupportedConversion('A Mermaid '.$kind.' diagram cannot be read yet'),
        };
    }
}
