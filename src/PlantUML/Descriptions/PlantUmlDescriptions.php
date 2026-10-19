<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML component, deployment or use case diagram read natively, drawn as PlantUML draws it. */
final readonly class PlantUmlDescriptions implements PlantUmlDiagram
{
    /**
     * @param array<string, DescriptionElement> $elements
     * @param array<string, DescriptionGroup> $groups
     * @param list<PlantUmlLink> $links
     * @param list<PlantUmlNote> $notes
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $elements,
        public array $groups = [],
        public array $links = [],
        public array $notes = [],
        public Direction $direction = Direction::TopBottom,
    ) {}

    /** The use case model when the diagram has actors or use cases, the graph model otherwise. */
    #[\Override]
    public function model(): Diagram
    {
        return DescriptionModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = DescriptionDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
