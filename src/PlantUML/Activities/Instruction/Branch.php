<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/**
 * One way through a decision: its test, the label on the arrow into it, the arrow into its
 * test from the one before for an `elseif`, and its steps — and the arrow out of it, as
 * written before the next `else`, `elseif` or `endif`.
 */
final class Branch
{
    public readonly InstructionList $list;
    /** The arrow out of its steps, written before the end of the branch. */
    public LinkRendering $inlinkRendering;
    /** What is written before the `else`, `elseif` or `endif` closing it: its colour leaves the branch. */
    public ?LinkRendering $special = null;
    /** `<<…>>` written after `endif`, colouring the diamond closing the decision. */
    public ?string $specialColors = null;

    /** @param ?list<string> $labelTest */
    public function __construct(
        public readonly LinkRendering $labelPositive,
        public readonly ?array $labelTest = null,
        public readonly ?string $color = null,
        public readonly LinkRendering $inlabel = new LinkRendering(),
    ) {
        $this->list = new InstructionList();
        $this->inlinkRendering = new LinkRendering();
    }

    public function add(Instruction $instruction): void
    {
        $this->list->add($instruction);
    }

    public function kill(): bool
    {
        return $this->list->kill();
    }

    public function addNote(PositionedNote $note): void
    {
        $this->list->addNote($note);
    }

    public function empty(): bool
    {
        return $this->list->empty();
    }

    public function last(): ?Instruction
    {
        return $this->list->last();
    }

    public function special(LinkRendering $link, ?string $colors = null): void
    {
        $this->special = $link;
        $this->specialColors = $colors;
    }
}
