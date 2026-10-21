<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML network diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlNwDiag implements PlantUmlDiagram
{
    /**
     * @param list<NwNetwork> $networks
     * @param list<NwServer> $servers
     * @param list<NwGroup> $groups
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $networks,
        public array $servers,
        public array $groups,
        public NwPlayField $field,
    ) {}

    /**
     * As the shared graph model: each network drawn a node, each server a box, joined to each
     * network it is on by a line without a head, its address there its label; servers on a
     * network made up, joined to each other.
     */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        $ids = [];
        foreach ($this->networks as $index => $network) {
            if (!$network->visible) {
                continue;
            }
            $ids[\spl_object_id($network)] = 'net'.$index;
            $nodes[] = new Node('net'.$index, $network->displayName(), Shape::Stadium);
        }
        $edges = [];
        $hidden = [];
        foreach ($this->servers as $index => $server) {
            $nodes[] = new Node('n'.$index, $server->description);
            foreach ($server->connections as $key => [, $address]) {
                $id = $ids[$key] ?? null;
                if (null === $id) {
                    $hidden[$key][] = 'n'.$index;
                    continue;
                }
                $edges[] = new Edge($id, 'n'.$index, $address, head: ArrowHead::None);
            }
        }
        foreach ($hidden as $peers) {
            for ($index = 1, $count = \count($peers); $index < $count; ++$index) {
                $edges[] = new Edge($peers[0], $peers[$index], head: ArrowHead::None);
            }
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return NwDrawing::draw($this);
    }
}
