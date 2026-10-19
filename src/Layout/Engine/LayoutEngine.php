<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** Places sized boxes, nested clusters and the edges between them, as a generator that yields between units of work. */
interface LayoutEngine
{
    /** @return \Generator<int, null, mixed, Placement> */
    public function place(LayoutGraph $graph): \Generator;
}
