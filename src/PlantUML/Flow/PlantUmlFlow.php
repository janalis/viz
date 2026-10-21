<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Flow;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML flow diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlFlow implements PlantUmlDiagram
{
    /**
     * @param list<FlowTile> $tiles
     * @param list<array{FlowTile, string, FlowTile, string}> $paths each path's start, its side, its end and its side
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $tiles,
        public array $paths,
    ) {}

    /** As the shared graph model: each box a node, each path an edge. */
    #[\Override]
    public function model(): Diagram
    {
        return new Graph(
            \array_map(static fn (FlowTile $tile): Node => new Node($tile->id, $tile->label), $this->tiles),
            \array_map(static fn (array $path): Edge => new Edge($path[0]->id, $path[2]->id), $this->paths),
        );
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return FlowDrawing::draw($this);
    }
}
