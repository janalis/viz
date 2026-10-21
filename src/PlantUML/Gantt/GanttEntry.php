<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * A row of a Gantt chart, as PlantUML's `AbstractTask` keeps one: its code and the name it
 * shows, the row it is drawn on when another's, what it is displayed as, its stereotypes.
 */
abstract class GanttEntry
{
    public ?self $row = null;
    public ?string $displayString = null;
    /** @var list<string> */
    public array $stereotypes = [];

    public function __construct(
        public readonly string $id,
        public readonly string $display,
    ) {}

    /** As PlantUML's `putInSameRowAs`, never its own. */
    public function sameRowAs(self $row): void
    {
        if ($this !== $row) {
            $this->row = $row;
        }
    }
}
