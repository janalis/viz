<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\PlantUML\Sequences\Arrow;

/**
 * An arrow as Rose draws it, end by end: the head at its first end and its last — `none`,
 * `normal`, `async` or `cross` — whether the head is whole or only its top or bottom half, a
 * circle at either end; its body, its slant, whether it was written right to left, and
 * whether it comes back to where it leaves.
 */
final readonly class ArrowConfig
{
    public function __construct(
        public string $head1 = 'none',
        public string $head2 = 'normal',
        public string $part1 = 'full',
        public string $part2 = 'full',
        public bool $circle1 = false,
        public bool $circle2 = false,
        /** `normal`, `dotted` or `hidden` */
        public string $body = 'normal',
        public int $inclination = 0,
        public bool $reverseDefine = false,
        public bool $self = false,
    ) {}

    /** A message's arrow from the participant it leaves to the one it reaches, its half head on the end that has a head. */
    public static function of(Arrow $arrow): self
    {
        $last = 'none' !== $arrow->head2;

        return new self(
            $arrow->head1,
            $arrow->head2,
            $last ? 'full' : $arrow->part,
            $last ? $arrow->part : 'full',
            $arrow->circle1,
            $arrow->circle2,
            $arrow->body,
            $arrow->inclination,
            $arrow->reversed,
        );
    }

    /** The arrow drawn from its other end. */
    public function reverse(): self
    {
        return new self($this->head2, $this->head1, $this->part2, $this->part1, $this->circle2, $this->circle1, $this->body, $this->inclination, $this->reverseDefine, $this->self);
    }

    /** The arrow coming back to where it leaves. */
    public function selfArrow(): self
    {
        return new self($this->head1, $this->head2, $this->part1, $this->part2, $this->circle1, $this->circle2, $this->body, $this->inclination, $this->reverseDefine, self: true);
    }

    /** `self`, `normal` — left to right — `reverse` or `both`, by which ends have heads. */
    public function direction(): string
    {
        return match (true) {
            $this->self => 'self',
            'none' === $this->head1 && 'none' !== $this->head2 => 'normal',
            'none' !== $this->head1 && 'none' === $this->head2 => 'reverse',
            default => 'both',
        };
    }

    /** The half of the head drawn, the last end's when it has a head. */
    public function part(): string
    {
        return 'none' !== $this->head2 ? $this->part2 : $this->part1;
    }

    /** How far down the arrow's first end is moved, when its last end has no head or a cross. */
    public function inclination1(): int
    {
        return \in_array($this->head2, ['none', 'cross'], strict: true) ? $this->inclination : 0;
    }

    /** How far down the arrow's last end is moved, unless its first end has an open head. */
    public function inclination2(): int
    {
        return 'async' === $this->head1 ? 0 : $this->inclination;
    }
}
