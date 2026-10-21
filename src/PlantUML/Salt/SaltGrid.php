<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `ElementPyramid`: widgets in rows and columns, each column as wide as its widest
 * widget and 2 more, each row as tall as its tallest, the narrowest and shortest cells sized
 * first; ruled as `{+` (around), `{^` (around, a title on the top), `{-` (between rows), `{!`
 * (between columns) or `{#` (all) asks, or not at all.
 */
final class SaltGrid implements SaltElement
{
    /** @var list<float> */
    private array $rowsStart = [];
    /** @var list<float> */
    private array $colsStart = [];
    private ?SaltLabel $title;

    /** @param list<array{SaltElement, SaltCell}> $cells in the order they were written */
    public function __construct(
        private readonly array $cells,
        private readonly int $rows,
        private readonly int $cols,
        private readonly string $strategy = ' ',
        ?string $title = null,
    ) {
        $this->title = null === $title ? null : new SaltLabel(\explode("\n", \str_replace('\n', replace: "\n", subject: $title)));
        $this->layout();
    }

    #[\Override]
    public function size(): array
    {
        return [$this->colsStart[\count($this->colsStart) - 1], $this->rowsStart[\count($this->rowsStart) - 1] + $this->titleHeight()];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        $marks = [];
        $horizontals = [];
        $verticals = [];
        if (\in_array($this->strategy, ['+', '^', '#'], strict: true)) {
            [$horizontals, $verticals] = $this->outside();
        }
        foreach ($this->cells as [$element, $cell]) {
            $top = $this->rowsStart[$cell->row] + (0 === $cell->row ? $this->titleHeight() / 2 : 0.0);
            $cellWidth = $this->colsStart[$cell->maxCol + 1] - $this->colsStart[$cell->col] - 1;
            $cellHeight = $this->rowsStart[$cell->row + 1] - $this->rowsStart[$cell->row] - 1;
            if (\in_array($this->strategy, ['-', '#'], strict: true)) {
                for ($col = $cell->col; $col <= $cell->maxCol; ++$col) {
                    $horizontals[$cell->row.','.$col] = [$cell->row, $col];
                    $horizontals[($cell->row + 1).','.$col] = [$cell->row + 1, $col];
                }
            }
            if (\in_array($this->strategy, ['!', '#'], strict: true)) {
                $verticals[$cell->row.','.$cell->col] = [$cell->row, $cell->col];
                $verticals[$cell->row.','.($cell->maxCol + 1)] = [$cell->row, $cell->maxCol + 1];
            }
            \array_push($marks, ...$element->marks($x + $this->colsStart[$cell->col] + 1, $y + $top + 1, $pass, $cellWidth, $cellHeight));
        }
        if (0 !== $pass) {
            return $marks;
        }
        foreach ($horizontals as [$row, $col]) {
            $marks[] = SaltPen::line($x + $this->colsStart[$col], $y + $this->rowsStart[$row], $x + $this->colsStart[$col + 1], $y + $this->rowsStart[$row]);
        }
        foreach ($verticals as [$row, $col]) {
            $marks[] = SaltPen::line($x + $this->colsStart[$col], $y + $this->rowsStart[$row], $x + $this->colsStart[$col], $y + $this->rowsStart[$row + 1]);
        }
        if (null !== $this->title) {
            [$titleWidth, $titleHeight] = $this->title->size();
            $marks[] = SaltPen::box($x + 6, $y, $titleWidth, $titleHeight, '#ffffff', '#ffffff');
            \array_push($marks, ...$this->title->marks($x + 6, $y));
        }

        return $marks;
    }

    /** @return array{array<string, array{int, int}>, array<string, array{int, int}>} the rules around it, by row and column */
    private function outside(): array
    {
        [$rows, $cols] = [\count($this->rowsStart), \count($this->colsStart)];
        [$horizontals, $verticals] = [[], []];
        for ($col = 0; $col < ($cols - 1); ++$col) {
            $horizontals['0,'.$col] = [0, $col];
            $horizontals[($rows - 1).','.$col] = [$rows - 1, $col];
        }
        for ($row = 0; $row < ($rows - 1); ++$row) {
            $verticals[$row.',0'] = [$row, 0];
            $verticals[$row.','.($cols - 1)] = [$row, $cols - 1];
        }

        return [$horizontals, $verticals];
    }

    /** As PlantUML's `init`. */
    private function layout(): void
    {
        $half = $this->titleHeight() / 2;
        $this->rowsStart = \array_fill(0, \max(1, $this->rows + 1), $half);
        $this->colsStart = \array_fill(0, \max(1, $this->cols + 1), value: 0.0);
        $byWidth = $this->cells;
        \usort($byWidth, static fn (array $a, array $b): int => [$a[1]->columns(), $a[1]->col] <=> [$b[1]->columns(), $b[1]->col]);
        foreach ($byWidth as [$element, $cell]) {
            self::ensure($this->colsStart, $cell->col, $cell->maxCol + 1, $element->size()[0] + 2);
        }
        $byRow = $this->cells;
        \usort($byRow, static fn (array $a, array $b): int => $a[1]->row <=> $b[1]->row);
        foreach ($byRow as [$element, $cell]) {
            self::ensure($this->rowsStart, $cell->row, $cell->row + 1, $element->size()[1] + (0 === $cell->row ? $half : 0.0) + 2);
        }
    }

    /** @param list<float> $starts */
    private static function ensure(array &$starts, int $first, int $last, float $size): void
    {
        $missing = $size - ($starts[$last] - $starts[$first]);
        if ($missing <= 0) {
            return;
        }
        for ($index = $last, $count = \count($starts); $index < $count; ++$index) {
            $starts[$index] += $missing;
        }
    }

    private function titleHeight(): float
    {
        return $this->title?->size()[1] ?? 0.0;
    }
}
