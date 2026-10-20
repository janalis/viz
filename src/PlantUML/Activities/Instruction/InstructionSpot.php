<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** A connector, `(A)`: a circle with a letter in it. */
final class InstructionSpot extends Noted implements Instruction
{
    public bool $killed = false;

    public function __construct(
        public readonly string $spot,
        private readonly LinkRendering $inLink,
        public readonly ?string $color = null,
    ) {}

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    public function kill(): bool
    {
        $this->killed = true;

        return true;
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return false;
    }
}
