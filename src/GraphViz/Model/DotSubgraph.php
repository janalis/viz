<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/**
 * A subgraph: its attributes, the nodes it holds at any depth in the order they joined it,
 * and the subgraphs inside it. One named `cluster…` is drawn as a box.
 */
final class DotSubgraph
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, true>   $html
     * @param array<string, true>   $nodes
     * @param list<DotSubgraph>     $subgraphs
     */
    public function __construct(
        public readonly string $name,
        public readonly ?DotSubgraph $parent = null,
        public array $attributes = [],
        public array $html = [],
        public array $nodes = [],
        public array $subgraphs = [],
        public readonly int $offset = 0,
    ) {}

    public function cluster(): bool
    {
        return \str_starts_with($this->name, 'cluster');
    }

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }
}
