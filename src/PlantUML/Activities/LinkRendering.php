<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

/** How an arrow of an activity diagram is drawn when its source says so: its colour and style, and its label. */
final readonly class LinkRendering
{
    /** @param ?list<string> $display the label's lines, none when not given */
    public function __construct(
        /** As written between `-[` and `]->`: colours and styles, `,`-separated. */
        public ?string $color = null,
        public ?array $display = null,
    ) {}

    /** @param ?list<string> $display */
    public function withDisplay(?array $display): self
    {
        return new self($this->color, $display);
    }

    public function withColor(?string $color): self
    {
        return new self($color, $this->display);
    }

    /** Whether it says nothing of the arrow. */
    public function none(): bool
    {
        return null === $this->color && null === $this->display;
    }
}
