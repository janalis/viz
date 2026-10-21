<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/** PlantUML's `TimeConstraint`: a span between two moments of a player, with heads at both ends and a text above. */
final readonly class TimingConstraint
{
    /** @param list<string> $label */
    public function __construct(
        public TimingTick $from,
        public TimingTick $to,
        public array $label,
        public float $margin,
        public ?string $color = null,
    ) {}

    public function containsStrict(TimingTick $tick): bool
    {
        return $this->from->time < $tick->time && $this->to->time > $tick->time;
    }
}
