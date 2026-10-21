<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * PlantUML's `OpenClose`: which weekdays and which days are open or closed, and any day
 * before or after which all are closed; a day's rate of work, 1 open or 0 closed.
 */
final class GanttCalendar
{
    /** @var array<int, bool> open or not, by weekday */
    private array $weekdays = [];
    /** @var array<int, bool> open or not, by day */
    private array $days = [];
    public ?int $offBefore = null;
    public ?int $offAfter = null;

    public function openWeekday(int $weekday): void
    {
        $this->weekdays[$weekday] = true;
    }

    public function closeWeekday(int $weekday): void
    {
        $this->weekdays[$weekday] = false;
    }

    public function openDay(int $day): void
    {
        $this->days[$day] = true;
    }

    public function closeDay(int $day): void
    {
        $this->days[$day] = false;
    }

    /** A copy with another calendar's weekdays and days over its own, as `mutateMe`. */
    public function with(?self $except): self
    {
        $result = clone $this;
        if (null !== $except) {
            $result->weekdays = $except->weekdays + $this->weekdays;
            $result->days = $except->days + $this->days;
        }

        return $result;
    }

    public function daysInWeek(): int
    {
        return 7 - \count(\array_keys($this->weekdays, filter_value: false, strict: true));
    }

    public function isClosed(int $day): bool
    {
        if ((null !== $this->offBefore && $day < $this->offBefore) || (null !== $this->offAfter && $day > $this->offAfter)) {
            return true;
        }

        return !($this->days[$day] ?? $this->weekdays[GanttTime::weekday($day)] ?? true);
    }

    /** As `asPiecewiseConstant`, on one day: open by weekday unless closed that day, or open that day; none outside its bounds. */
    public function rate(int $day): GanttRate
    {
        $open = $this->weekdays[GanttTime::weekday($day)] ?? true;
        if (\array_key_exists($day, $this->days)) {
            $open = $this->days[$day];
        }
        if ((null !== $this->offBefore && $day < $this->offBefore) || (null !== $this->offAfter && $day > $this->offAfter)) {
            $open = false;
        }

        return $open ? GanttRate::one() : GanttRate::zero();
    }
}
