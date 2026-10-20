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
        /** Where across its tail the edge leaves, along its rank's order as a layout runs it: -0.5 at the start, 0.5 at the end, 0 at the centre. A layout turned sideways may run it from the far end. */
        public float $tailPort = 0.0,
        /** Where across its head the edge arrives, likewise. */
        public float $headPort = 0.0,
        /** How long the edge would be, centre to centre, for a layout that places nodes by it, as neato does; nought for its own default. */
        public float $length = 0.0,
    ) {}
}
