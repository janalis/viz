<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/** PlantUML's `Highlight`: a span of time shaded across every player, dashed at both ends, with a caption. */
final readonly class TimingHighlight
{
    /** @param list<string> $caption */
    public function __construct(
        public TimingTick $from,
        public TimingTick $to,
        public array $caption,
        public ?string $back = null,
        public ?string $line = null,
    ) {}
}
