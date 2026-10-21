<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * PlantUML's `TimeScale`s: where a moment stands across the chart and how wide its day is —
 * every day a cell of 1.6 times the day font, scaled down for weeks, months, quarters and
 * years; from 1970 when no first day is given, else from the first day; closed days of no
 * width when hidden — and where a resource's load is summed up: each day, or each week.
 */
final class GanttScale
{
    /** @var array<int, int> the open days before each day, by day, when closed days are hidden */
    private array $openBefore = [];

    public function __construct(
        private readonly float $cell,
        private readonly int $origin,
        private readonly string $breaking,
        private readonly ?GanttCalendar $hidden = null,
    ) {}

    public function x(int $seconds): float
    {
        if (null !== $this->hidden) {
            return $this->openDaysBefore(GanttTime::day($seconds)) * $this->cell;
        }

        return (($seconds - $this->origin) * $this->cell) / GanttTime::DAY;
    }

    public function width(int $seconds): float
    {
        return null !== $this->hidden && $this->hidden->isClosed(GanttTime::day($seconds)) ? 0.0 : $this->cell;
    }

    /** Whether a resource's load is written for the day ending here: every day, each Sunday, or each seventh day from 1970. */
    public function breaking(int $seconds): bool
    {
        return match ($this->breaking) {
            'sunday' => 7 === GanttTime::weekday(GanttTime::day($seconds)),
            'week' => 6 === (((GanttTime::day($seconds) % 7) + 7) % 7),
            default => true,
        };
    }

    private function openDaysBefore(int $day): int
    {
        $first = GanttTime::day($this->origin);
        if ($day <= $first) {
            return 0;
        }
        if (!\array_key_exists($day, $this->openBefore)) {
            $count = 0;
            for ($current = $first; $current < $day; ++$current) {
                $count += $this->width(GanttTime::start($current)) > 0 ? 1 : 0;
            }
            $this->openBefore[$day] = $count;
        }

        return $this->openBefore[$day];
    }
}
