<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** An action: its text in a box of its style, `kill` ending the flow after it. */
final class InstructionSimple extends Noted implements Instruction
{
    public bool $killed = false;

    /** @param list<string> $label */
    public function __construct(
        public readonly array $label,
        private readonly LinkRendering $inLink,
        /** `<<…>>` as written after it: its colours and its box's style. */
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
        $this->killed = true;

        return true;
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return false;
    }
}
