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
    ) {}
}
