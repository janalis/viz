<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;

/** One of the pieces PlantUML's Rose skin draws a sequence diagram with: a head, an arrow, a note, a frame. */
interface Component
{
    /** @return array{float, float} the width and height it would have */
    public function size(): array;

    /**
     * Its marks, its area's top left at a point: those behind everything, or those in front.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, Area $area, Layer $layer): array;
}
