<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** Where the flow starts, stops, or ends every flow: `start`, `stop` or `end`. */
final class InstructionTerminal extends Noted implements Instruction
{
    public function __construct(
        /** `start`, `stop` or `end` */
        public readonly string $kind,
        private readonly LinkRendering $inLink,
        public readonly ?string $stereogroup = null,
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
        return false;
    }
}
