<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * PlantUML's `LoadIntegrator`, over day-long stretches of a constant rate: from a moment,
 * forward or back, each stretch's seconds times its rate taken from the load until it is
 * used up, the last stretch only as far as it needs.
 */
final class GanttIntegrator
{
    /** @param \Closure(int): GanttRate $rate a day's rate of work */
    public static function forward(int $start, int $load, \Closure $rate): int
    {
        $remaining = new GanttRate($load);
        $current = $start;
        for ($count = 0; $count < 9999; ++$count) {
            if ($remaining->zeroed()) {
                return $current;
            }
            $day = GanttTime::day($current);
            $end = GanttTime::start($day + 1);
            $value = $rate($day);
            if ($value->zeroed()) {
                $current = $end;
                continue;
            }
            $seconds = $end - $current;
            $done = $value->times(new GanttRate($seconds));
            if (($done->num * $remaining->den) >= ($remaining->num * $done->den)) {
                return $current + \intdiv($remaining->num * $value->den, $remaining->den * $value->num);
            }
            $remaining = $remaining->plus(new GanttRate(-$done->num, $done->den));
            $current = $end;
        }

        throw new PlantUmlRefusal('Infinite loop detected in PiecewiseConstant iteration');
    }

    /** @param \Closure(int): GanttRate $rate a day's rate of work */
    public static function backward(int $end, int $load, \Closure $rate): int
    {
        $remaining = new GanttRate($load);
        $current = $end;
        for ($count = 0; $count < 9999; ++$count) {
            if ($remaining->zeroed()) {
                return $current;
            }
            $day = GanttTime::midnight($current) ? GanttTime::day($current) - 1 : GanttTime::day($current);
            $start = GanttTime::start($day);
            $value = $rate($day);
            if ($value->zeroed()) {
                $current = $start;
                continue;
            }
            $seconds = $current - $start;
            $done = $value->times(new GanttRate($seconds));
            if (($done->num * $remaining->den) >= ($remaining->num * $done->den)) {
                return $current - \intdiv($remaining->num * $value->den, $remaining->den * $value->num);
            }
            $remaining = $remaining->plus(new GanttRate(-$done->num, $done->den));
            $current = $start;
        }

        throw new PlantUmlRefusal('Infinite loop detected in PiecewiseConstant iteration');
    }
}
