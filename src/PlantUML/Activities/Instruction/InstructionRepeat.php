<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/**
 * `repeat` up to `repeat while`: an optional action at its start, its body, its test with the
 * labels on the way back and out, and a `backward` action on the way back.
 */
final class InstructionRepeat implements Container
{
    public readonly InstructionList $list;
    public bool $killed = false;
    /** @var ?list<string> the `backward` action's text */
    public ?array $backward = null;
    public ?string $backwardStereogroup = null;
    /** @var list<PositionedNote> notes beside the `backward` action */
    public array $backwardNotes = [];
    public LinkRendering $incoming1;
    public LinkRendering $incoming2;
    /** @var ?list<string> */
    public ?array $test = null;
    /** @var ?list<string> */
    public ?array $yes = null;
    /** @var ?list<string> */
    public ?array $out = null;
    public bool $tested = false;
    /** The arrow out of its body, as written before `repeat while`. */
    public LinkRendering $endLink;
    public ?string $stereogroup2 = null;

    /** @param ?list<string> $startLabel */
    public function __construct(
        public readonly Container $parent,
        private readonly LinkRendering $inLink,
        public readonly ?array $startLabel = null,
        public readonly ?string $stereogroup = null,
    ) {
        $this->list = new InstructionList();
        $this->incoming1 = new LinkRendering();
        $this->incoming2 = new LinkRendering();
        $this->endLink = new LinkRendering();
    }

    #[\Override]
    public function add(Instruction $instruction): void
    {
        $this->list->add($instruction);
    }

    /** @param list<string> $label */
    public function backward(array $label, ?string $stereogroup, LinkRendering $incoming1, LinkRendering $incoming2): void
    {
        $this->backward = $label;
        $this->backwardStereogroup = $stereogroup;
        $this->incoming1 = $incoming1;
        $this->incoming2 = $incoming2;
    }

    /**
     * `repeat while`: its test, the labels on the way back and out, and the arrow back, if written.
     *
     * @param ?list<string> $test
     * @param ?list<string> $yes
     * @param ?list<string> $out
     */
    public function test(?array $test, ?array $yes, ?array $out, LinkRendering $endLink, LinkRendering $back, ?string $stereogroup2): void
    {
        $this->stereogroup2 = $stereogroup2;
        $this->test = $test;
        $this->yes = $yes;
        $this->out = $out;
        $this->endLink = $endLink;
        if (!$back->none()) {
            $this->incoming1 = $back;
        }
        $this->tested = true;
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
        if (null === $this->backward) {
            $this->list->addNote($note);

            return;
        }
        $this->backwardNotes[] = $note;
    }

    #[\Override]
    public function containsBreak(): bool
    {
        return $this->list->containsBreak();
    }

    /** Whether it is the last step of the list it stands in, as its last step is when nothing follows it. */
    public function lastOfParent(): bool
    {
        return $this->parent instanceof InstructionList && $this->parent->last() === $this;
    }
}
