<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** A directed edge between two node or cluster ids, spanning at least $minLength ranks. */
final readonly class LayoutEdge
{
    public function __construct(
        public int $index,
        public string $from,
        public string $to,
        public int $minLength = 1,
        /** How hard the edge pulls its ends together: shorter, straighter, more vertical. */
        public int $weight = 1,
        /** Whether the edge takes part in ranking; an edge that does not is still drawn. */
        public bool $constraint = true,
        /** The size of the edge's label, which a layout may give room of its own; zero for none. */
        public float $labelWidth = 0.0,
        public float $labelHeight = 0.0,
    ) {}
}
