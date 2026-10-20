<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

/** A block steps are added to as they come: a list, a decision, a loop, a fork, a group. */
interface Container extends Instruction
{
    public function add(Instruction $instruction): void;
}
