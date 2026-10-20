<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;
use Janalis\Viz\PlantUML\PlantUmlRefusal;

/** `switch`, its `case`s, up to `endswitch`. */
final class InstructionSwitch extends Noted implements Collection
{
    /** @var list<Branch> */
    public array $cases = [];
    /** `<<…>>` written after `endswitch`, colouring the diamond closing it. */
    public ?string $endColors = null;
    private ?Branch $current = null;

    /** @param ?list<string> $test */
    public function __construct(
        public readonly Container $parent,
        public readonly ?array $test,
        private readonly LinkRendering $topInLink,
        /** `<<…>>` written after it. */
        public readonly ?string $stereogroup = null,
    ) {}

    /** @throws PlantUmlRefusal before its first `case`, as PlantUML refuses it */
    #[\Override]
    public function add(Instruction $instruction): void
    {
        ($this->current ?? throw new PlantUmlRefusal("No 'case' in this switch"))->add($instruction);
    }

    /**
     * `case`: a branch labelled with its value, the arrow written before it leaving the one before.
     *
     * @param ?list<string> $label
     */
    public function case(?array $label, LinkRendering $next): void
    {
        $this->current?->special($next);
        $this->current = new Branch(new LinkRendering(display: $label), $label, inlabel: new LinkRendering(display: $label));
        $this->cases[] = $this->current;
    }

    public function end(LinkRendering $next, ?string $colors): void
    {
        $this->endColors = $colors;
        $this->current?->special($next, $colors);
    }

    #[\Override]
    public function kill(): bool
    {
        return null !== $this->current && $this->current->kill();
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->topInLink;
    }

    #[\Override]
    public function addNote(PositionedNote $note): void
    {
        null === $this->current || $this->current->empty() ? parent::addNote($note) : $this->current->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        foreach ($this->cases as $case) {
            if ($case->list->containsBreak()) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function last(): ?Instruction
    {
        return [] === $this->cases ? null : $this->cases[\count($this->cases) - 1]->last();
    }
}
