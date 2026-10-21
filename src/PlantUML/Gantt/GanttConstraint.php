<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `GanttConstraint`: an arrow from one instant to another, with a colour and a style. */
final class GanttConstraint
{
    public function __construct(
        public readonly GanttInstant $source,
        public readonly GanttInstant $dest,
        public ?string $color = null,
        public ?string $style = null,
    ) {}

    public function isOn(GanttEntry $task): bool
    {
        return $this->source->moment === $task || $this->dest->moment === $task;
    }

    /** As PlantUML's `isThereRightArrow`: an arrow coming into the task's end, or one from its end to another's. */
    public function rightArrow(GanttEntry $task): bool
    {
        return ($this->dest->moment === $task && $this->dest->end) || ($this->source->moment === $task && $this->dest->end && $this->source->end);
    }
}
