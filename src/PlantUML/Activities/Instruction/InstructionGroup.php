<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** A frame around steps: `partition`, `group`, `package`, `rectangle` or `card`, up to `}`. */
final class InstructionGroup implements Collection
{
    public readonly InstructionList $list;
    public ?PositionedNote $note = null;

    /** @param list<string> $title */
    public function __construct(
        public readonly Container $parent,
        public readonly array $title,
        private readonly LinkRendering $inLink,
        /** `partition`, `group`, `package`, `rectangle` or `card` */
        public readonly string $type = 'partition',
        public readonly ?string $color = null,
        public readonly ?string $stereotype = null,
    ) {
        $this->list = new InstructionList();
    }

    #[\Override]
    public function add(Instruction $instruction): void
    {
        $this->list->add($instruction);
    }

    #[\Override]
    public function last(): ?Instruction
    {
        return $this->list->last();
    }

    #[\Override]
    public function kill(): bool
    {
        return $this->list->kill();
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    public function addNote(PositionedNote $note): void
    {
        if ($this->list->empty()) {
            $this->note = $note;

            return;
        }
        $this->list->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return $this->list->containsBreak();
    }
}
