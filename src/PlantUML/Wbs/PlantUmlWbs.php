<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML work breakdown structure, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlWbs implements PlantUmlDiagram
{
    /** @param list<array{WbsElement, WbsElement, ?string, ?string}> $links each arrow's ends, colour and stereotype */
    public function __construct(
        public PlantUmlDocument $document,
        public WbsElement $root,
        public array $links = [],
    ) {}

    #[\Override]
    public function model(): Diagram
    {
        return WbsModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return WbsDrawing::draw($this);
    }
}
