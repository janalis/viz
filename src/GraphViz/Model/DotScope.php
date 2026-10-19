<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/**
 * The defaults one scope sets itself: the root graph's, or a subgraph's. A scope sees its
 * parent's defaults as they stand, unless it sets its own.
 */
final class DotScope
{
    /**
     * @param array<string, array{string, bool}> $node value and whether it is HTML, by attribute
     * @param array<string, array{string, bool}> $edge
     */
    public function __construct(
        public readonly ?DotSubgraph $subgraph,
        public readonly ?DotScope $parent = null,
        public array $node = [],
        public array $edge = [],
    ) {}

    /**
     * The defaults in force for a kind of object, the nearest scope's winning.
     *
     * @param 'node'|'edge' $kind
     * @return array<string, array{string, bool}>
     */
    public function defaults(string $kind): array
    {
        $own = 'node' === $kind ? $this->node : $this->edge;

        return null === $this->parent ? $own : \array_replace($this->parent->defaults($kind), $own);
    }
}
