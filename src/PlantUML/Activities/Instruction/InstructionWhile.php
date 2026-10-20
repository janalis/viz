<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/**
 * `while` up to `endwhile`: its test, the label while it goes on, its body, the label out of
 * it, a `backward` action on the way back, and a stop or end right after it, which PlantUML
 * draws on its way out.
 */
final class InstructionWhile extends Noted implements Collection
{
    public readonly InstructionList $list;
    public LinkRendering $outColor;
    public bool $killed = false;
    /** A `stop` or `end` right after it. */
    public ?InstructionTerminal $special = null;
    /** @var ?list<string> the `backward` action's text */
    public ?array $backward = null;
    public ?string $backwardStereogroup = null;
    public LinkRendering $incoming1;
    public LinkRendering $incoming2;
    private bool $tested = false;
    private bool $backwardCalled = false;

    /**
     * @param list<string> $test
     * @param ?list<string> $yes
     */
    public function __construct(
        public readonly Container $parent,
        public readonly array $test,
        private readonly LinkRendering $inLink,
        public ?array $yes,
        public readonly ?string $color = null,
    ) {
        $this->list = new InstructionList();
        $this->outColor = new LinkRendering();
        $this->incoming1 = new LinkRendering();
        $this->incoming2 = new LinkRendering();
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
        if ($this->tested) {
            $this->killed = true;

            return true;
        }

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
        $this->list->empty() ? parent::addNote($note) : $this->list->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return $this->list->containsBreak();
    }

    /** @param list<string> $label */
    public function backward(array $label, ?string $stereogroup, LinkRendering $incoming1, LinkRendering $incoming2): void
    {
        $this->backward = $label;
        $this->backwardStereogroup = $stereogroup;
        $this->incoming1 = $incoming1;
        $this->incoming2 = $incoming2;
        $this->backwardCalled = true;
    }

    /** `endwhile`: the arrow written before it goes back to the test, unless a `backward` took it. */
    public function incoming(LinkRendering $incoming): void
    {
        if (!$this->backwardCalled) {
            $this->incoming1 = $incoming;
            $this->incoming2 = $incoming;
        }
        $this->tested = true;
    }
}
