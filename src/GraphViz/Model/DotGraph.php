<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/** A whole DOT graph, as the language resolves it: every attribute in place on its object. */
final class DotGraph
{
    /**
     * @param array<string, string>   $attributes the root graph's own
     * @param array<string, true>     $html
     * @param array<string, DotNode>  $nodes in the order they were first written
     * @param list<DotEdge>           $edges
     * @param list<DotSubgraph>       $subgraphs top-level ones
     */
    public function __construct(
        public readonly string $name = '',
        public readonly bool $directed = true,
        public readonly bool $strict = false,
        public array $attributes = [],
        public array $html = [],
        public array $nodes = [],
        public array $edges = [],
        public array $subgraphs = [],
    ) {}

    /** @var array<string, \Janalis\Viz\GraphViz\DotImage> the images its nodes and labels name, read where the host allows, by name */
    public array $images = [];

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * The nodes of a set in the order the graph created them, which is how the language
     * walks a subgraph's nodes.
     *
     * @param array<array-key, true> $set
     * @return list<string>
     */
    public function ordered(array $set): array
    {
        $ordered = [];
        foreach ($this->nodes as $name => $_) {
            if (!\array_key_exists($name, $set)) {
                continue;
            }
            $ordered[] = $name;
        }

        return $ordered;
    }

    /** @return list<DotSubgraph> every subgraph at any depth, parents first */
    public function allSubgraphs(): array
    {
        $all = [];
        $pending = \array_reverse($this->subgraphs);
        while ([] !== $pending) {
            $subgraph = \array_pop($pending);
            $all[] = $subgraph;
            \array_push($pending, ...\array_reverse($subgraph->subgraphs));
        }

        return $all;
    }
}
