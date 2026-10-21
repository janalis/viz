<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/** PlantUML's `TimeMessage`: an arrow from a player at a moment to a player at another, with a text. */
final readonly class TimingMessage
{
    /** @param list<string> $label */
    public function __construct(
        public TimingPlayer $from,
        public ?TimingTick $fromTick,
        public TimingPlayer $to,
        public ?TimingTick $toTick,
        public array $label,
        public ?string $color = null,
    ) {}
}
