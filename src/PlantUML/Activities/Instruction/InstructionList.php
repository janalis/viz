<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** Steps one after another, and the arrow out of the last when its block ends with one. */
final class InstructionList extends Noted implements Collection
{
    /** @var list<Instruction> */
    public array $all = [];
    public ?LinkRendering $outRendering = null;

    #[\Override]
    public function add(Instruction $instruction): void
    {
        $this->all[] = $instruction;
    }

    #[\Override]
    public function last(): ?Instruction
    {
        return [] === $this->all ? null : $this->all[\count($this->all) - 1];
    }

    public function empty(): bool
    {
        return [] === $this->all;
    }

    /** Whether it is a single stop, end, spot or ended action, nothing more. */
    public function onlySingleStopOrSpot(): bool
    {
        if (1 !== \count($this->all)) {
            return false;
        }
        $last = $this->all[0];

        return match (true) {
            $last instanceof InstructionSimple => $last->killed,
            $last instanceof InstructionSpot => true,
            $last instanceof InstructionTerminal => 'start' !== $last->kind && [] === $last->notes,
            default => false,
        };
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->all[0]->inLink();
    }

    #[\Override]
    public function kill(): bool
    {
        return $this->last()?->kill() ?? false;
    }

    #[\Override]
    public function addNote(PositionedNote $note): void
    {
        $last = $this->last();
        null === $last ? parent::addNote($note) : $last->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return [] !== \array_filter($this->all, static fn (Instruction $instruction): bool => $instruction->containsBreak());
    }
}
