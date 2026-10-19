<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/** A real a fixed distance from another, moving it when moved. */
final class RealDelta extends RealMoveable
{
    public function __construct(
        private readonly Real $delegated,
        private readonly float $diff,
    ) {
        parent::__construct($delegated->line);
    }

    #[\Override]
    public function value(): float
    {
        return $this->delegated->value() + $this->diff;
    }

    #[\Override]
    public function atLeast(float $delta): Real
    {
        return new self($this->delegated->atLeast($delta), $this->diff);
    }

    #[\Override]
    public function ensureBiggerThan(Real $other): void
    {
        $this->delegated->ensureBiggerThan(new self($other, -$this->diff));
    }

    #[\Override]
    public function move(float $delta): void
    {
        if ($this->delegated instanceof RealMoveable) {
            $this->delegated->move($delta);
        }
    }
}
