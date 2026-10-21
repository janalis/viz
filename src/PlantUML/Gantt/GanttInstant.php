<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * PlantUML's `TaskInstant`: a moment's start or end, some days after or before it — each of
 * them counted, or, for working days, only the open ones.
 */
final readonly class GanttInstant
{
    /** @param ?\Closure(int): GanttRate $calendar the chart's calendar, to count only its open days */
    public function __construct(
        public GanttMoment $moment,
        public bool $end,
        public int $delta = 0,
        public ?\Closure $calendar = null,
    ) {}

    /** As PlantUML's `getInstantPrecise`. */
    public function at(): int
    {
        $value = $this->end ? $this->moment->end() : $this->moment->start();
        $added = 0;
        while ($added < $this->delta) {
            $value += GanttTime::DAY;
            if (null !== $this->calendar && ($this->calendar)(GanttTime::day($value))->zeroed()) {
                continue;
            }
            ++$added;
        }

        return $value + (\min(0, $this->delta) * GanttTime::DAY);
    }

    public function isTask(): bool
    {
        return $this->moment instanceof GanttEntry;
    }

    /** As PlantUML's `sameRowAs`: two tasks, one drawn on the other's row. */
    public function sameRowAs(self $other): bool
    {
        $mine = $this->moment;
        $theirs = $other->moment;

        return $mine instanceof GanttEntry && $theirs instanceof GanttEntry && ($mine === $theirs->row || $theirs === $mine->row);
    }
}
