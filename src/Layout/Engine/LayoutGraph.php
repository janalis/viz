<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

use Janalis\Viz\Model\Direction;

/**
 * A graph to lay out, in any unit as long as it is one unit: boxes already measured,
 * clusters listed parents first, edges by index.
 */
final readonly class LayoutGraph
{
    /**
     * @param list<LayoutNode>    $nodes
     * @param list<LayoutEdge>    $edges
     * @param list<LayoutCluster> $clusters every cluster, each after its parent
     * @param list<LayoutRankSet> $rankSets nodes whose ranks are tied together
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $clusters = [],
        public Direction $direction = Direction::TopBottom,
        public Spacing $spacing = new Spacing(),
        /** Whether a cluster that an edge leaves takes its parent's direction, whatever it declares. */
        public bool $inheritAcrossLinks = true,
        /** The direction of a cluster that declares none; null inherits its parent's. */
        public ?Direction $undeclared = null,
        /** The gap between a cluster's label and its content. */
        public float $titleGap = 0.0,
        public array $rankSets = [],
        /** The height-to-width ratio a layout stretches its positions to, never shrinking them, as dot's `ratio`; null for none. */
        public ?float $aspect = null,
        /** Whether edges running together merge into one line, as dot's `concentrate` merges them. */
        public bool $concentrate = false,
        /** Whether ranks are set across clusters at once, as dot's `newrank`, rather than cluster by cluster. */
        public bool $newRank = false,
        /** Whether a rank set took a node out of a cluster written before it, which dot is seen to rank cluster by cluster all the same. */
        public bool $emptied = false,
        /** @var ?array{float, float} the width and height a layout stretches its positions to fill, where it is smaller both ways, as dot's `ratio=fill` with a `size`; else it keeps to {@see $aspect} */
        public ?array $fill = null,
        /** @var array<string, array{string, ?string}> each node a rank set took out of a cluster written before it: that cluster, which dot is seen to rank it in all the same, and the cluster the set is written in, null at the top */
        public array $held = [],
    ) {}
}
