<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** `fork` up to `end fork` or `end merge`, or `split` up to `end split`: flows side by side. */
final class InstructionFork extends Noted implements Container
{
    /** @var non-empty-list<InstructionList> */
    public array $forks;
    /** `fork`, `merge` or `split` */
    public string $style;
    public ?string $label = null;
    /** The swimlane its last `fork again` or its end was written in, where its bar below stands. */
    public ?string $laneOut = null;
    private bool $finished = false;

    public function __construct(
        public readonly Container $parent,
        private readonly LinkRendering $inLink,
        bool $split = false,
        public readonly ?string $stereogroup = null,
    ) {
        $this->forks = [new InstructionList()];
        $this->style = $split ? 'split' : 'fork';
    }

    #[\Override]
    public function add(Instruction $instruction): void
    {
        $this->lastList()->add($instruction);
    }

    /** `fork again` or `split again`, the arrow written before it leaving the flow before. */
    public function again(?LinkRendering $out): void
    {
        $this->out($out);
        $this->forks[] = new InstructionList();
    }

    /** `end fork`, `end merge` or `end split`, the arrow written before it leaving the last flow. */
    public function end(?LinkRendering $out, ?string $style = null, ?string $label = null): void
    {
        $this->out($out);
        $this->finished = true;
        $this->style = $style ?? $this->style;
        $this->label = $label;
    }

    #[\Override]
    public function kill(): bool
    {
        return $this->lastList()->kill();
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    public function addNote(PositionedNote $note): void
    {
        $this->finished && 'split' !== $this->style ? parent::addNote($note) : $this->lastList()->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return [] !== \array_filter($this->forks, static fn (InstructionList $fork): bool => $fork->containsBreak());
    }

    private function lastList(): InstructionList
    {
        return $this->forks[\count($this->forks) - 1];
    }

    private function out(?LinkRendering $out): void
    {
        if (null !== $out) {
            $this->lastList()->outRendering = $out;
        }
    }
}
