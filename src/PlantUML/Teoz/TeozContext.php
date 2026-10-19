<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;

/**
 * What every tile is built with: the skin, the participants' columns, the diagram's events,
 * the origins across and down, and — once solved — the diagram's left and right borders.
 */
final class TeozContext
{
    private float $border1 = 0.0;
    private float $border2 = 0.0;

    public function __construct(
        public readonly Rose $rose,
        public readonly LivingSpaces $spaces,
        public readonly TeozEvents $events,
        public readonly Real $xOrigin,
        public readonly Real $yOrigin,
    ) {}

    public function border(float $left, float $right): void
    {
        $this->border1 = $left;
        $this->border2 = $right;
    }

    public function border1(): float
    {
        return $this->border1;
    }

    public function border2(): float
    {
        return $this->border2;
    }
}
