<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's salt `Cell`: the rows and columns a widget of a grid takes, a column more for each `*` after it. */
final class SaltCell
{
    public int $maxCol;

    public function __construct(
        public readonly int $row,
        public readonly int $col,
    ) {
        $this->maxCol = $col;
    }

    public function columns(): int
    {
        return $this->maxCol - $this->col + 1;
    }
}
