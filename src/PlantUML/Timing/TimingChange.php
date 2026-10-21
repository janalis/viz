<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/**
 * PlantUML's `ChangeState`: a player's state from a moment, or from before any — one state, or
 * two at once — with a comment and a colour; `{...}` unknown, `{-}` flat, `{hidden}` not drawn.
 */
final readonly class TimingChange
{
    /** @param non-empty-list<string> $states */
    public function __construct(
        public ?TimingTick $when,
        public array $states,
        public ?string $comment = null,
        public ?string $back = null,
    ) {}

    public function state(): string
    {
        return $this->states[0];
    }

    public function blank(): bool
    {
        return '{...}' === $this->states[0];
    }

    public function hidden(): bool
    {
        return '{hidden}' === $this->states[0];
    }

    public function flat(): bool
    {
        return '{-}' === $this->states[0];
    }
}
