<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** `if`, its `elseif`s and its `else`, up to `endif`. */
final class InstructionIf extends Noted implements Collection
{
    /** @var non-empty-list<Branch> */
    public array $thens;
    public ?Branch $else = null;
    /** Whether the `else` was written, not made up at `endif`. */
    public bool $written = false;
    public LinkRendering $outColor;
    private Branch $current;
    private bool $ended = false;

    /** @param ?list<string> $test */
    public function __construct(
        public readonly Container $parent,
        ?array $test,
        LinkRendering $whenThen,
        private readonly LinkRendering $topInLink,
        ?string $color,
        /** `<<…>>` written after it. */
        public readonly ?string $stereogroup = null,
    ) {
        $this->current = new Branch($whenThen, $test, $color);
        $this->thens = [$this->current];
        $this->outColor = new LinkRendering();
    }

    #[\Override]
    public function add(Instruction $instruction): void
    {
        $this->current->add($instruction);
    }

    /** `else`, unless there is one already. */
    public function toElse(LinkRendering $whenElse, LinkRendering $next): bool
    {
        $this->current->special($next);
        if (null !== $this->else) {
            return false;
        }
        $this->current->inlinkRendering = $next;
        $this->else = new Branch($whenElse);
        $this->written = true;
        $this->current = $this->else;

        return true;
    }

    /**
     * `elseif`, unless the `else` came already.
     *
     * @param ?list<string> $test
     */
    public function elseIf(LinkRendering $inlabel, ?array $test, LinkRendering $whenThen, LinkRendering $next, ?string $color): bool
    {
        if (null !== $this->else) {
            return false;
        }
        $this->current->special($next);
        $this->current = new Branch($whenThen, $test, $color, $inlabel);
        $this->thens[] = $this->current;

        return true;
    }

    public function endif(LinkRendering $next, ?string $colors): void
    {
        $this->ended = true;
        $this->else ??= new Branch(new LinkRendering());
        $this->else->special($next, $colors);
        $this->current->inlinkRendering = $next;
    }

    #[\Override]
    public function kill(): bool
    {
        if (!$this->ended) {
            return $this->current->kill();
        }
        // As PlantUML, once ended: the first branch's last step and the else's are killed, if they can be.
        $first = $this->thens[0]->last();
        if (null !== $first && !$first->kill()) {
            return false;
        }
        $else = $this->else?->last();

        return null === $else || $else->kill();
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->topInLink;
    }

    #[\Override]
    public function addNote(PositionedNote $note): void
    {
        $this->ended || $this->current->empty() ? parent::addNote($note) : $this->current->addNote($note);
    }

    #[\Override]
    public function containsBreak(): bool
    {
        foreach ([...$this->thens, ...(null === $this->else ? [] : [$this->else])] as $branch) {
            if ($branch->list->containsBreak()) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function last(): ?Instruction
    {
        return null === $this->else ? $this->thens[\count($this->thens) - 1]->last() : $this->else->last();
    }
}
