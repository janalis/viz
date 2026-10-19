<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;

/**
 * A diagram before layout, in the terms Mermaid flowcharts, Graphviz and PlantUML
 * share: nodes, directed edges between them, nested clusters, a direction. Each
 * language's writer turns it into source; what a language cannot express is
 * dropped the way its writer documents.
 */
final readonly class Graph implements Diagram
{
    /**
     * @param list<Node>    $nodes
     * @param list<Edge>    $edges
     * @param list<Cluster> $clusters top-level clusters; a node belongs to at most one
     */
    public function __construct(
        public array $nodes = [],
        public array $edges = [],
        public array $clusters = [],
        public Direction $direction = Direction::TopBottom,
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::Graph;
    }

    #[\Override]
    public function graph(): self
    {
        return $this;
    }

    /**
     * The nodes that sit in no cluster, in declaration order.
     *
     * @return list<Node>
     */
    public function looseNodes(): array
    {
        $clustered = [];
        $visit = static function (Cluster $cluster) use (&$visit, &$clustered): void {
            foreach ($cluster->nodes as $id) {
                $clustered[$id] = true;
            }
            foreach ($cluster->clusters as $inner) {
                $visit($inner);
            }
        };
        foreach ($this->clusters as $cluster) {
            $visit($cluster);
        }

        return \array_values(\array_filter($this->nodes, static fn (Node $node): bool => !\array_key_exists($node->id, $clustered)));
    }

    public function node(string $id): ?Node
    {
        return \array_find($this->nodes, static fn (Node $node): bool => $node->id === $id);
    }
}
