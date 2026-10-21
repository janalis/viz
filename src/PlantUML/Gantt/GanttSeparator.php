<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `TaskSeparator`: a line across the chart, with a comment or not. */
final class GanttSeparator extends GanttEntry
{
    public function __construct(
        int $index,
        public readonly ?string $comment,
    ) {
        parent::__construct('##'.$index, '##'.$index);
    }
}
