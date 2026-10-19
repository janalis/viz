<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/**
 * How a sequence arrow is drawn, as PlantUML configures it: its body, the head at each end —
 * `none`, `normal`, `async` (open) or `cross` — half of it only, circles at its ends, its
 * colour, its slant, and whether it points both ways or was written right to left.
 */
final readonly class Arrow
{
    public function __construct(
        /** `normal`, `dotted` or `hidden` */
        public string $body = 'normal',
        public string $head1 = 'none',
        public string $head2 = 'normal',
        /** `full`, `top` or `bottom` */
        public string $part = 'full',
        public bool $circle1 = false,
        public bool $circle2 = false,
        public ?string $color = null,
        public int $inclination = 0,
        public bool $both = false,
        public bool $reversed = false,
    ) {}

    public function withBody(string $body): self
    {
        return clone($this, ['body' => $body]);
    }

    public function withHeads(string $head1, string $head2): self
    {
        return clone($this, ['head1' => $head1, 'head2' => $head2]);
    }

    public function withPart(string $part): self
    {
        return clone($this, ['part' => $part]);
    }

    public function withCircles(bool $circle1, bool $circle2): self
    {
        return clone($this, ['circle1' => $circle1, 'circle2' => $circle2]);
    }

    public function withColor(?string $color): self
    {
        return clone($this, ['color' => $color]);
    }

    public function withInclination(int $inclination): self
    {
        return clone($this, ['inclination' => $inclination]);
    }

    public function dotted(): bool
    {
        return 'dotted' === $this->body;
    }

    /** The head at the end the arrow points to; head1 is at the end it leaves, whichever way it was written. */
    public function head(): string
    {
        return $this->head2;
    }

    /** Whether there is a head at the end the arrow leaves too. */
    public function twoHeaded(): bool
    {
        return 'none' !== $this->head1;
    }
}
