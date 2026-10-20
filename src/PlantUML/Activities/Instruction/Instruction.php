<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** One step of an activity diagram as PlantUML builds it from its commands, or a block of steps. */
interface Instruction
{
    /** The arrow into it, as written before it. */
    public function inLink(): LinkRendering;

    /** Ends the flow after it, as `kill` or `detach` does; whether it can. */
    public function kill(): bool;

    /** Puts a note beside it, or beside its last step. */
    public function addNote(PositionedNote $note): void;

    /** Whether it holds a `break`. */
    public function containsBreak(): bool;
}
