<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML wire diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlWire implements PlantUmlDiagram
{
    /**
     * @param list<WireSpot> $spots
     * @param list<WireLink> $links
     * @param list<array{string, string}> $joins each link's blocks, by their dotted paths
     */
    public function __construct(
        public PlantUmlDocument $document,
        public WireBlock $root,
        public array $spots,
        public array $links,
        public array $joins,
    ) {}

    /** As the shared graph model: each block a node by its dotted path, each link an edge, its heads as it bears them. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        $walk = static function (WireBlock $block, string $path) use (&$walk, &$nodes): void {
            foreach ($block->children as $child) {
                $id = '' === $path ? $child->name : $path.'.'.$child->name;
                $nodes[] = new Node($id, \str_replace('_', replace: ' ', subject: $child->name));
                $walk($child, $id);
            }
        };
        $walk($this->root, path: '');
        $edges = [];
        foreach ($this->links as $index => $link) {
            [$from, $to] = $this->joins[$index];
            $edges[] = new Edge($from, $to, \implode("\n", $link->label ?? []), head: $link->forward ? ArrowHead::Arrow : ArrowHead::None, tail: $link->backward ? ArrowHead::Arrow : ArrowHead::None);
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return WireDrawing::draw($this);
    }
}
