<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/**
 * A Creole table, as PlantUML's `AtomTable`: each column as wide as its widest cell, each row
 * as high as its highest; rows and cells on their colours, a cell set right moved across; then
 * the grid over all.
 */
final class CreoleTable implements CreoleAtom
{
    /** @var list<array{?string, list<array{CreoleSheet, ?string}>}> each row's colour and cells, each cell's colour */
    private array $rows = [];

    public function __construct(
        private readonly string $line,
    ) {}

    public function row(?string $color): void
    {
        $this->rows[] = [$color, []];
    }

    public function cell(CreoleSheet $cell, ?string $color): void
    {
        $this->rows[\count($this->rows) - 1][1][] = [$cell, $color];
    }

    #[\Override]
    public function size(): array
    {
        [$columns, $rows] = $this->grid();

        return [\array_sum($columns), \array_sum($rows)];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        [$columns, $heights] = $this->grid();
        [$width, $height] = [\array_sum($columns), \array_sum($heights)];
        $marks = [];
        $top = $y;
        foreach ($this->rows as $index => [$color, $cells]) {
            $rowHeight = $heights[$index];
            if (null !== $color) {
                $marks[] = ClassBox::rectangle($x, $top, $width, $rowHeight, 'none', $color, 0.0, 0.0);
            }
            $left = $x;
            foreach ($cells as $column => [$cell, $back]) {
                if (null !== $back) {
                    $marks[] = ClassBox::rectangle($left, $top, $columns[$column], $rowHeight, 'none', $back, 0.0, 0.0);
                }
                $shift = 'right' === $cell->alignment() ? $columns[$column] - $cell->size()[0] : 0.0;
                \array_push($marks, ...$cell->marks($left + $shift, $top));
                $left += $columns[$column];
            }
            $top += $rowHeight;
        }
        foreach ([0.0, ...$heights] as $index => $unused) {
            $at = $y + \array_sum(\array_slice($heights, offset: 0, length: $index));
            $marks[] = new Mark(MarkKind::Polyline, [[$x, $at], [$x + $width, $at]], stroke: $this->line, strokeWidth: 1.0);
        }
        foreach ([0.0, ...$columns] as $index => $unused) {
            $at = $x + \array_sum(\array_slice($columns, offset: 0, length: $index));
            $marks[] = new Mark(MarkKind::Polyline, [[$at, $y], [$at, $y + $height]], stroke: $this->line, strokeWidth: 1.0);
        }

        return $marks;
    }

    /** @return array{list<float>, list<float>} each column's width and each row's height */
    private function grid(): array
    {
        $columns = [];
        $rows = [];
        foreach ($this->rows as [, $cells]) {
            $height = 0.0;
            foreach ($cells as $column => [$cell]) {
                [$cellWidth, $cellHeight] = $cell->size();
                $columns[$column] = \max($columns[$column] ?? 0.0, $cellWidth);
                $height = \max($height, $cellHeight);
            }
            $rows[] = $height;
        }

        return [\array_values($columns), $rows];
    }
}
