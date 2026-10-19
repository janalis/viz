<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

use Janalis\Viz\Source\Unsupported;

/**
 * The reals of one axis and the forces between them, solved as PlantUML solves them: each
 * force in turn pushes its moving real past its fixed one by its distance, again and again
 * until none moves.
 */
final class RealLine
{
    /** The most rounds a solve may take before it is given up. */
    private const int MAX_ROUNDS = 99_999;

    /** @var list<PositiveForce> */
    private array $forces = [];
    /** @var list<Real> */
    private array $reals = [];
    private float $min = 0.0;
    private float $max = 0.0;

    public function register(Real $real): void
    {
        $this->reals[] = $real;
    }

    public function addForce(PositiveForce $force): void
    {
        $this->forces[] = $force;
    }

    /** @throws Unsupported when the forces never settle */
    public function compile(): void
    {
        for ($round = 0; $round <= self::MAX_ROUNDS; ++$round) {
            $moved = false;
            foreach ($this->forces as $force) {
                $moved = $force->apply() || $moved;
            }
            if (!$moved) {
                $values = [0.0, ...\array_map(static fn (Real $real): float => $real->value(), $this->reals)];
                $this->min = \min($values);
                $this->max = \max($values);

                return;
            }
        }

        throw new Unsupported('The sequence diagram cannot be laid out: its constraints never settle');
    }

    public function min(): float
    {
        return $this->min;
    }

    public function max(): float
    {
        return $this->max;
    }
}
