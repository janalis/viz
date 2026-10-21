<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML activity diagram in the legacy syntax, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlLegacyActivity implements PlantUmlDiagram
{
    /**
     * @param array<string, LegacyEntity> $entities by name, in the order created
     * @param array<string, LegacyGroup> $groups
     * @param list<PlantUmlLink> $links
     * @param list<PlantUmlNote> $notes notes on links
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $entities,
        public array $groups = [],
        public array $links = [],
        public array $notes = [],
        public Direction $direction = Direction::TopBottom,
    ) {}

    #[\Override]
    public function model(): Diagram
    {
        return LegacyActivityModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = LegacyActivityDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
