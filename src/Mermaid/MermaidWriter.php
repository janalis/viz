<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Mermaid\Source\MermaidGraphWriter;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Source\UnsupportedConversion;
use Janalis\Viz\Source\Writer;

/** The shared models written as Mermaid, each family as its own diagram type. */
final readonly class MermaidWriter implements Writer
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph];
    }

    #[\Override]
    public function write(Diagram $diagram): string
    {
        return match (true) {
            $diagram instanceof Graph => MermaidGraphWriter::write($diagram),
            default => throw new UnsupportedConversion('A '.$diagram->family()->value.' diagram has no Mermaid writer'),
        };
    }
}
