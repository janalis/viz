<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** `break`: the flow leaves the loop it is in. */
final class InstructionBreak extends Noted implements Instruction
{
    public function __construct(
        private readonly LinkRendering $inLink,
    ) {}

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    public function kill(): bool
    {
        return false;
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return true;
    }
}
