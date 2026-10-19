<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/** A real with a value of its own, which forces move: an origin, or one at least a distance past another. */
final class RealImpl extends RealMoveable
{
    public function __construct(
        RealLine $line,
        private float $current = 0.0,
    ) {
        parent::__construct($line);
    }

    /** A new axis's origin, at 0. */
    public static function origin(): self
    {
        return new self(new RealLine());
    }

    #[\Override]
    public function value(): float
    {
        return $this->current;
    }

    #[\Override]
    public function move(float $delta): void
    {
        $this->current += $delta;
    }

    #[\Override]
    public function atLeast(float $delta): Real
    {
        $real = new self($this->line, $this->current + $delta);
        $this->line->addForce(new PositiveForce($this, $real, $delta));

        return $real;
    }

    #[\Override]
    public function ensureBiggerThan(Real $other): void
    {
        $this->line->addForce(new PositiveForce($other, $this, 0.0));
    }

    /** Solves every real of this origin's axis. */
    public function compile(): void
    {
        $this->line->compile();
    }
}
