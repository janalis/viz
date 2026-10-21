<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/** PlantUML's `Positionner2`: each widget of a grid in the next column, or the first of the next row after one that ends its line. */
final class SaltPositions
{
    private int $row = 0;
    private int $col = 0;
    private int $maxRow = 0;
    private int $maxCol = 0;
    /** @var list<array{SaltElement, SaltCell}> */
    private array $cells = [];
    private ?SaltCell $last = null;

    /** @param array{SaltElement, bool} $read a widget, and whether it ends its line */
    public function add(array $read): void
    {
        [$element, $ends] = $read;
        $this->last = new SaltCell($this->row, $this->col);
        $this->cells[] = [$element, $this->last];
        $this->stretch();
        [$this->row, $this->col] = $ends ? [$this->row + 1, 0] : [$this->row, $this->col + 1];
    }

    /**
     * A `*`: the widget before it a column wider.
     *
     * @param array{SaltElement, bool} $read the `*`, and whether it ends its line
     */
    public function widen(array $read): void
    {
        $ends = $read[1];
        $this->stretch();
        [$this->row, $this->col] = $ends ? [$this->row + 1, 0] : [$this->row, $this->col + 1];
        $last = $this->last ?? throw new PlantUmlRefusal('A * with no widget before it');
        ++$last->maxCol;
    }

    public function grid(string $strategy, ?string $title = null): SaltGrid
    {
        [$rows, $cols] = [$this->maxRow + 1, $this->maxCol + 1];
        foreach ($this->cells as [, $cell]) {
            [$rows, $cols] = [\max($rows, $cell->row), \max($cols, $cell->maxCol)];
        }

        return new SaltGrid($this->cells, $rows, $cols, $strategy, $title);
    }

    private function stretch(): void
    {
        [$this->maxRow, $this->maxCol] = [\max($this->maxRow, $this->row), \max($this->maxCol, $this->col)];
    }
}
