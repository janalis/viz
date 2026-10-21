<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML mind map, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlMindMap implements PlantUmlDiagram
{
    /** @param list<MindMap> $maps one under the other */
    public function __construct(
        public PlantUmlDocument $document,
        public array $maps,
        public Direction $direction = Direction::LeftRight,
    ) {}

    #[\Override]
    public function model(): Diagram
    {
        return MindMapModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return MindMapDrawing::draw($this);
    }
}
