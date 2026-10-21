<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Git;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML git log, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlGit implements PlantUmlDiagram
{
    /** @param list<GitNode> $nodes its commits, each run drawn in one box, in the order PlantUML lays them out */
    public function __construct(
        public PlantUmlDocument $document,
        public array $nodes,
    ) {}

    /** A node by its first commit, each joined to its parents. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        $edges = [];
        foreach ($this->nodes as $node) {
            $nodes[] = new Node($node->texts[0], \implode("\n", $node->texts));
            foreach ($node->down as $parent) {
                $edges[] = new Edge($node->texts[0], $parent->texts[0]);
            }
        }

        return new Graph($nodes, $edges);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        return yield from GitDrawing::draw($this);
    }
}
