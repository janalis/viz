<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `MomentImpl`: named days, from the first one's start to the last one's end. */
final readonly class GanttSpan implements GanttMoment
{
    public function __construct(
        private int $start,
        private int $end,
    ) {}

    #[\Override]
    public function start(): int
    {
        return $this->start;
    }

    #[\Override]
    public function end(): int
    {
        return $this->end;
    }
}
