<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** Nodes whose ranks are tied together. */
final readonly class LayoutRankSet
{
    /** @param list<string> $nodes */
    public function __construct(
        public RankKind $kind,
        public array $nodes,
    ) {}
}
