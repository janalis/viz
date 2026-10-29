<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML JSON or YAML diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlJson implements PlantUmlDiagram
{
    /** @param list<JsonHighlight> $highlights */
    public function __construct(
        public PlantUmlDocument $document,
        public JsonNode $root,
        public array $highlights = [],
    ) {}

    #[\Override]
    public function model(): Diagram
    {
        return JsonModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = JsonDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
