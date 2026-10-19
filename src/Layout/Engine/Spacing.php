<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** The gaps between ranks, between neighbors in a rank, and around the whole. */
final readonly class Spacing
{
    public function __construct(
        public float $rankGap = 8.0,
        public float $nodeGap = 6.0,
        public float $padding = 4.0,
    ) {}
}
