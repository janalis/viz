<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML salt wireframe, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlSalt implements PlantUmlDiagram
{
    /** @param list<string> $texts each widget's text, in the order written */
    public function __construct(
        public PlantUmlDocument $document,
        public SaltElement $root,
        public array $texts,
    ) {}

    /** As the shared graph model: each widget's text a node, in the order written. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = \array_map(static fn (int $index, string $text): Node => new Node('n'.$index, $text), \array_keys($this->texts), $this->texts);
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    /**
     * As PlantUML's `PSystemSalt` draws it: the widgets, then the menus open over them, 5 in;
     * as large as they would be, 5 more round — or as far as anything is drawn, and 5 —
     * then to the next whole point.
     */
    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];
        [$width, $height] = $this->root->size();
        $marks = [...$this->root->marks(0.0, 0.0, 0, $width, $height), ...$this->root->marks(0.0, 0.0, 1, $width, $height)];
        // What is drawn past the widgets, as an open menu, takes the diagram that far and 5 more, with no margin past it.
        [$right, $bottom] = [$width + 5, $height + 5];
        foreach ($marks as $mark) {
            if (MarkKind::Text === $mark->kind) {
                continue;
            }
            foreach ($mark->points as [$x, $y]) {
                [$right, $bottom] = [\max($right, $x), \max($bottom, $y)];
            }
        }

        return PlantUmlFrame::inset($this->document, $marks, \floor($right + 5) - 9, \floor($bottom + 5) - 9, 5.0);
    }
}
