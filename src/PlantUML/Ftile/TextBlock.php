<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Something drawn at a point with a size of its own: a label, a note, room left empty. */
interface TextBlock
{
    /** @return array{float, float} */
    public function size(): array;

    /** @return list<Shape> drawn with its top left at a point */
    public function shapes(float $x, float $y): array;
}
