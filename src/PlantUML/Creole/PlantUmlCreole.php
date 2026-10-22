<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML Creole diagram, read natively and drawn as PlantUML draws one: its sheet alone, with no margin. */
final readonly class PlantUmlCreole implements PlantUmlDiagram
{
    /** @param list<string> $lines */
    public function __construct(
        public PlantUmlDocument $document,
        public array $lines,
        public CreoleSheet $sheet,
    ) {}

    /** As the shared graph model: the text a single node. */
    #[\Override]
    public function model(): Diagram
    {
        return new Graph([new Node('n0', \implode("\n", $this->lines))]);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];
        [$width, $height] = $this->sheet->size();

        return PlantUmlFrame::bare($this->document, $this->sheet->marks(0.0, 0.0), $width, $height);
    }
}
