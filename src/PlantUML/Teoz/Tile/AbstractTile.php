<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\TeozContext;

/** What most tiles share: no arrow to touch with, no constraint of their own, drawn as wide as they measure. */
abstract class AbstractTile implements Tile
{
    public function __construct(
        protected readonly TeozContext $context,
    ) {}

    #[\Override]
    public function contact(): float
    {
        return -1.0;
    }

    #[\Override]
    public function addConstraints(): void {}

    #[\Override]
    public function drawnMinX(): Real
    {
        return $this->minX();
    }

    #[\Override]
    public function drawnMaxX(): Real
    {
        return $this->maxX();
    }

    #[\Override]
    public function resolved(): void {}
}
