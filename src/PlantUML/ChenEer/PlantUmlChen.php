<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML Chen EER diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlChen implements PlantUmlDiagram
{
    /**
     * @param array<string, ChenElement> $elements by id, in the order written
     * @param list<PlantUmlLink> $links
     * @param array<int, bool> $signs by link, the subset sign across its middle: true for a superset
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $elements,
        public array $links = [],
        public array $signs = [],
        public Direction $direction = Direction::TopBottom,
    ) {}

    #[\Override]
    public function model(): Diagram
    {
        return ChenModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        return yield from ChenDrawing::draw($this);
    }
}
