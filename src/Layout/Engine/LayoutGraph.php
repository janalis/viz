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
    ) {}
}
