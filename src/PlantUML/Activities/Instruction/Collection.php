<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

/** A block whose last step an arrow's colour reaches back to: a list, a decision, a while loop, a group. */
interface Collection extends Container
{
    /** The step added last, if any. */
    public function last(): ?Instruction;
}
