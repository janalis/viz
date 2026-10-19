<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Mermaid\Source\MermaidClassWriter;
use Janalis\Viz\Mermaid\Source\MermaidEntityWriter;
use Janalis\Viz\Mermaid\Source\MermaidGraphWriter;
use Janalis\Viz\Mermaid\Source\MermaidStateWriter;
use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Source\UnsupportedConversion;
use Janalis\Viz\Source\Writer;

/** The shared models written as Mermaid, each family as its own diagram type. */
final readonly class MermaidWriter implements Writer
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph, Family::State, Family::Classes, Family::Entity];
    }

    #[\Override]
    public function write(Diagram $diagram): string
    {
        return match (true) {
            $diagram instanceof StateDiagram => MermaidStateWriter::write($diagram),
            $diagram instanceof ClassDiagram => MermaidClassWriter::write($diagram),
            $diagram instanceof EntityDiagram => MermaidEntityWriter::write($diagram),
            $diagram instanceof Graph => MermaidGraphWriter::write($diagram),
            default => throw new UnsupportedConversion('A '.$diagram->family()->value.' diagram has no Mermaid writer'),
        };
    }
}
