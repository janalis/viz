<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML state diagram read natively: what its lines declared, drawn as PlantUML draws it. */
final readonly class PlantUmlStates implements PlantUmlDiagram
{
    /**
     * @param array<string, StateNode> $nodes
     * @param array<string, list<string>> $composites each composite state's region separators, by its id
     * @param list<PlantUmlLink> $links
     * @param list<PlantUmlNote> $notes
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $nodes,
        public array $composites = [],
        public array $links = [],
        public array $notes = [],
        public bool $hideEmptyDescription = false,
        public Direction $direction = Direction::TopBottom,
    ) {}

    #[\Override]
    public function model(): StateDiagram
    {
        return StateModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = StateDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
