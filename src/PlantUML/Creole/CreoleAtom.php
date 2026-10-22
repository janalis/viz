<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;

/** A piece of a Creole line, as PlantUML's `Atom`: its size, how far it stands off the line's foot, and its marks from its top left. */
interface CreoleAtom
{
    /** @return array{float, float} its width and height */
    public function size(): array;

    /** How far below the line's foot its own foot stands, as `getStartingAltitude`. */
    public function altitude(): float;

    /** @return list<Mark> */
    public function marks(float $x, float $y): array;
}
