<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/** A real a distance from another that is read anew each time, as a margin found only late. */
final class RealDeltaLive extends RealMoveable
{
    /** @param \Closure(): float $delta */
    public function __construct(
        private readonly Real $delegated,
        private readonly \Closure $delta,
    ) {
        parent::__construct($delegated->line);
    }

    #[\Override]
    public function value(): float
    {
        return $this->delegated->value() + ($this->delta)();
    }

    #[\Override]
    public function atLeast(float $delta): Real
    {
        return new self($this->delegated->atLeast($delta), $this->delta);
    }

    #[\Override]
    public function ensureBiggerThan(Real $other): void
    {
        $delta = $this->delta;
        $this->delegated->ensureBiggerThan(new self($other, static fn (): float => -$delta()));
    }

    #[\Override]
    public function move(float $delta): void
    {
        if ($this->delegated instanceof RealMoveable) {
            $this->delegated->move($delta);
        }
    }
}
