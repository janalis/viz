<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML EBNF diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlEbnf implements PlantUmlDiagram
{
    /** @param list<EbnfRule|list<string>> $items the rules and the notes, in order */
    public function __construct(
        public PlantUmlDocument $document,
        public array $items,
    ) {}

    /** As the shared graph model: each rule a box of its name, each note a note, in order. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        foreach ($this->items as $index => $item) {
            $nodes[] = $item instanceof EbnfRule
                ? new Node('n'.$index, $item->expression->name() ?? '', Shape::Box)
                : new Node('n'.$index, \implode("\n", $item), Shape::Note);
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return EbnfDrawing::ebnf($this);
    }
}
