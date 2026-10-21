<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** How a canvas draws swimlanes: each moved across by its offset, or all in place to measure them. */
final readonly class LaneView
{
    /** @param array<string, float> $offsets each swimlane's move across, by name */
    public function __construct(
        public array $offsets = [],
        public bool $measuring = true,
    ) {}

    public function offset(string $lane): float
    {
        return $this->offsets[$lane] ?? 0.0;
    }
}
