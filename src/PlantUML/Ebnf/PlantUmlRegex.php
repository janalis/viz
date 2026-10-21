<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML regex diagram, read natively and drawn as PlantUML draws it: the railroad of its last regex. */
final readonly class PlantUmlRegex implements PlantUmlDiagram
{
    public function __construct(
        public PlantUmlDocument $document,
        public EbnfTile $tile,
    ) {}

    /** As the shared graph model: one box of the regex as written. */
    #[\Override]
    public function model(): Diagram
    {
        $lines = \array_values(\array_filter(\array_map(\trim(...), $this->document->lines), static fn (string $line): bool => '' !== $line));
        $title = $this->document->captions['title'] ?? null;

        return new Graph([new Node('n0', \implode("\n", $lines))], title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return EbnfDrawing::regex($this);
    }
}
