<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `Resource`: someone working on tasks, off on days of their own. */
final class GanttResource
{
    public readonly GanttCalendar $calendar;

    public function __construct(
        public readonly string $name,
    ) {
        $this->calendar = new GanttCalendar();
    }
}
