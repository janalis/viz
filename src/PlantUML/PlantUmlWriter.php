<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\PlantUML\Source\PlantUmlClassWriter;
use Janalis\Viz\PlantUML\Source\PlantUmlEntityWriter;
use Janalis\Viz\PlantUML\Source\PlantUmlGraphWriter;
use Janalis\Viz\PlantUML\Source\PlantUmlSequenceWriter;
use Janalis\Viz\PlantUML\Source\PlantUmlStateWriter;
use Janalis\Viz\Source\UnsupportedConversion;
use Janalis\Viz\Source\Writer;

/** The shared models written as PlantUML, each family as its own diagram type. */
final readonly class PlantUmlWriter implements Writer
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph, Family::State, Family::Classes, Family::Entity, Family::Sequence];
    }

    #[\Override]
    public function write(Diagram $diagram): string
    {
        return match (true) {
            $diagram instanceof StateDiagram => PlantUmlStateWriter::write($diagram),
            $diagram instanceof ClassDiagram => PlantUmlClassWriter::write($diagram),
            $diagram instanceof EntityDiagram => PlantUmlEntityWriter::write($diagram),
            $diagram instanceof SequenceDiagram => PlantUmlSequenceWriter::write($diagram),
            $diagram instanceof Graph => PlantUmlGraphWriter::write($diagram),
            default => throw new UnsupportedConversion('A '.$diagram->family()->value.' diagram has no PlantUML writer'),
        };
    }
}
