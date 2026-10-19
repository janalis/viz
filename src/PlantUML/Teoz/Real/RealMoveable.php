<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/** A real a force may move. */
abstract class RealMoveable extends Real
{
    abstract public function move(float $delta): void;
}
