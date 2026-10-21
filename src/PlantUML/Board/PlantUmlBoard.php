<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Board;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML board, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlBoard implements PlantUmlDiagram
{
    /** @param list<BoardCard> $activities */
    public function __construct(
        public PlantUmlDocument $document,
        public array $activities,
    ) {}

    /** As the shared graph model: each card a box, joined to the card above it by a line without a head. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        $edges = [];
        /** @var array<int, string> $ids by card */
        $ids = [];
        foreach ($this->activities as $activity) {
            foreach ($activity->all() as $card) {
                $id = 'n'.\count($nodes);
                $ids[\spl_object_id($card)] = $id;
                $nodes[] = new Node($id, $card->text);
                if (null !== $card->parent) {
                    $edges[] = new Edge($ids[\spl_object_id($card->parent)], $id, head: ArrowHead::None);
                }
            }
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return BoardDrawing::draw($this);
    }
}
