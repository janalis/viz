<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/**
 * A position on one axis that teoz, PlantUML's sequence layout, solves for: fixed or pushed
 * by forces, the maximum, the minimum or the middle of others, or one of them moved by a
 * distance. Every real of a line is solved at once when its origin compiles.
 */
abstract class Real
{
    public function __construct(
        public readonly RealLine $line,
    ) {
        $line->register($this);
    }

    abstract public function value(): float;

    /** A real a fixed distance from this one. */
    public function plus(float $delta): Real
    {
        return new RealDelta($this, $delta);
    }

    /** A real at least a distance past this one, pushed further by the forces on it. */
    abstract public function atLeast(float $delta): Real;

    /** Keeps this real at or past another. */
    abstract public function ensureBiggerThan(Real $other): void;
}
