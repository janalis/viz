<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML class diagram read natively: what its lines declared, drawn as PlantUML draws it. */
final readonly class PlantUmlClasses implements PlantUmlDiagram
{
    /**
     * @param array<string, ClassEntity> $entities
     * @param list<PlantUmlLink> $links
     * @param array<string, ClassGroup> $groups
     * @param list<PlantUmlNote> $notes
     * @param list<array{bool, ?string, bool, string}> $visibility each `show` or `hide`: shown, of what, only when empty, which part
     * @param list<string> $leaves the classifiers and floating notes, by id, in the order they were made
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $entities,
        public array $links,
        public array $groups = [],
        public array $notes = [],
        public array $visibility = [],
        public Direction $direction = Direction::TopBottom,
        public array $leaves = [],
    ) {}

    #[\Override]
    public function model(): ClassDiagram
    {
        return ClassModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = ClassDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
