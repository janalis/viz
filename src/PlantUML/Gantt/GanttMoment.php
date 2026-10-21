<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** Something with a start and an end, as PlantUML's `Moment`: a task, a group, or days named alike. */
interface GanttMoment
{
    public function start(): int;

    public function end(): int;
}
