<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** One measured box. Margins reserve space around it, for a self-loop say, without growing what is drawn. */
final readonly class LayoutNode
{
    public function __construct(
        public string $id,
        public string $label,
        public float $width,
        public float $height,
        /** The innermost cluster holding the node; null at the top level. */
        public ?string $cluster = null,
        /** Where the node comes in its scope, against its sibling nodes and clusters. */
        public int $order = 0,
        public float $marginX = 0.0,
        public float $marginY = 0.0,
        /** `out` to keep the heads of its edges out, or `in` the tails of its edges in, in the order the edges come, as dot's `ordering` does; null for neither. */
        public ?string $ordering = null,
    ) {}
}
