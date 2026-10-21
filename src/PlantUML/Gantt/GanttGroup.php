<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/** PlantUML's `TaskGroup`: tasks and groups under a name, from the first one's start to the last one's end. */
final class GanttGroup extends GanttEntry implements GanttMoment
{
    /** @var list<GanttTask|self> */
    public array $children = [];

    public function __construct(
        string $name,
        public readonly ?self $parent,
    ) {
        parent::__construct($name, $name);
    }

    #[\Override]
    public function start(): int
    {
        $starts = \array_map(static fn (GanttMoment $child): int => $child->start(), $this->children);

        return [] === $starts ? throw new PlantUmlRefusal('An empty group') : \min($starts);
    }

    #[\Override]
    public function end(): int
    {
        $ends = \array_map(static fn (GanttMoment $child): int => $child->end(), $this->children);

        return [] === $ends ? throw new PlantUmlRefusal('An empty group') : \max($ends);
    }
}
