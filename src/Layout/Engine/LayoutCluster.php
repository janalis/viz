<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

use Janalis\Viz\Model\Direction;

/** A frame around nodes and other clusters, its label measured. */
final readonly class LayoutCluster
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $parent = null,
        /** The direction the cluster declares; null when it declares none. */
        public ?Direction $direction = null,
        /** The narrowest the frame may be, to fit its label. */
        public float $minWidth = 0.0,
        /** The band the label takes above the content. */
        public float $labelHeight = 0.0,
        /** Where the cluster comes in its scope, against its sibling nodes and clusters. */
        public int $order = 0,
        public float $marginX = 0.0,
        public float $marginY = 0.0,
    ) {}
}
