<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphNode;

/** An intrinsic grid cell; nested cells are translated without changing their contents. */
final readonly class C4Tile
{
    /** @param array<int, GraphNode> $nodes
     * @param list<GraphCluster> $clusters
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $nodes = [],
        public array $clusters = [],
    ) {}
}
