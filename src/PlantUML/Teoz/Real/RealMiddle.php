<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

use Janalis\Viz\Source\Unsupported;

/** The middle of two moveable reals, moving both half the way when moved. */
final class RealMiddle extends RealMoveable
{
    public function __construct(
        private readonly RealMoveable $first,
        private readonly RealMoveable $second,
    ) {
        parent::__construct($first->line);
    }

    #[\Override]
    public function value(): float
    {
        return ($this->first->value() + $this->second->value()) / 2;
    }

    /** @throws Unsupported */
    #[\Override]
    public function atLeast(float $delta): Real
    {
        throw new Unsupported('A middle of reals cannot be pushed past');
    }

    #[\Override]
    public function ensureBiggerThan(Real $other): void
    {
        $this->line->addForce(new PositiveForce($other, $this, 0.0));
    }

    #[\Override]
    public function move(float $delta): void
    {
        $this->first->move($delta / 2);
        $this->second->move($delta / 2);
    }
}
