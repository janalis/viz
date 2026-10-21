<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/** PlantUML's `TimingNote`: a note above or below a player's ribbon, at a moment or at its start. */
final readonly class TimingNote
{
    /** @param list<string> $lines */
    public function __construct(
        public ?TimingTick $when,
        public array $lines,
        /** `top` or `bottom` */
        public string $position,
    ) {}
}
