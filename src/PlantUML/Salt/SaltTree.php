<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `ElementTree`: a row for each entry, indented 10 for each `+`, its other cells in
 * columns 10 apart after the widest; grey lines joining each entry to the one above it, a
 * small box where an entry has entries under it; ruled as `T+`, `T-`, `T!` or `T#` asks.
 */
final class SaltTree implements SaltElement
{
    private const float MARGIN = 10.0;
    private const string GREY = '#888888';

    /** @var list<array{int, SaltText, list<SaltElement>}> each entry's depth, its text, and its other cells */
    private array $entries = [];

    public function __construct(
        private readonly string $strategy,
    ) {}

    public function entry(string $text): void
    {
        $depth = \strspn($text, characters: '+');
        $this->entries[] = [$depth, new SaltText(\trim(\substr($text, $depth))), []];
    }

    public function cell(SaltElement $element): void
    {
        $last = \count($this->entries) - 1;
        if ($last >= 0) {
            $this->entries[$last][2][] = $element;
        }
    }

    #[\Override]
    public function size(): array
    {
        $height = \array_sum(\array_map($this->rowHeight(...), $this->entries));
        $others = $this->others();
        $total = 0.0;
        foreach ($others as $width) {
            $total += ($total > 0 ? self::MARGIN : 0.0) + $width;
        }

        return [$this->first() + $total + ($total > 0 ? self::MARGIN : 0.0) + 2, $height];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        $first = $this->first();
        $others = $this->others();
        $marks = [];
        $rows = [0.0];
        $cols = [0.0, $first + (self::MARGIN / 2)];
        foreach ($others as $other) {
            $cols[] = $cols[\count($cols) - 1] + $other + self::MARGIN;
        }
        $top = 0.0;
        $joints = [];
        foreach ($this->entries as $entry) {
            [$depth, $text, $cells] = $entry;
            \array_push($marks, ...$text->marks($x + ($depth * 10), $y + $top, 0, 0.0, 0.0));
            $left = $first + self::MARGIN;
            foreach ($cells as $index => $cell) {
                \array_push($marks, ...$cell->marks($x + $left, $y + $top, 0, 0.0, 0.0));
                $left += ($others[$index] ?? 0.0) + self::MARGIN;
            }
            $rowHeight = $this->rowHeight($entry);
            $joints[] = [($depth * 10) - 7, $top + ($rowHeight / 2) - 1];
            $top += $rowHeight;
            $rows[] = $top;
        }
        \array_push($marks, ...self::skeleton($joints, $x, $y));
        if (' ' === $this->strategy) {
            return $marks;
        }

        return [...$marks, ...self::rules($rows, $cols, $this->strategy, $x, $y)];
    }

    /**
     * PlantUML's `Skeleton`: a box 2 across at each entry with deeper ones after it, and a line
     * down from the last shallower entry before each and across to it.
     *
     * @param list<array{float, float}> $joints
     * @return list<\Janalis\Viz\Draw\Mark>
     */
    private static function skeleton(array $joints, float $x, float $y): array
    {
        $marks = [];
        foreach ($joints as $index => [$jointX, $jointY]) {
            if (($joints[$index + 1][0] ?? -\INF) > $jointX) {
                $marks[] = SaltPen::box($x + $jointX, $y + $jointY, 2.0, 2.0, 'none', self::GREY);
            }
            $parent = null;
            for ($before = 0; $before < $index; ++$before) {
                if ($joints[$before][0] >= $jointX) {
                    continue;
                }
                $parent = $joints[$before];
            }
            if (null === $parent) {
                continue;
            }
            [$parentX, $parentY] = $parent;
            $marks[] = SaltPen::line($x + $parentX + 1, $y + $parentY + 3, $x + $parentX + 1, $y + $jointY + 1, self::GREY);
            $marks[] = SaltPen::line($x + $parentX + 1, $y + $jointY + 1, $x + $jointX - 1, $y + $jointY + 1, self::GREY);
        }

        return $marks;
    }

    /**
     * PlantUML's `Grid2`.
     *
     * @param list<float> $rows
     * @param list<float> $cols
     * @return list<\Janalis\Viz\Draw\Mark>
     */
    private static function rules(array $rows, array $cols, string $strategy, float $x, float $y): array
    {
        [$left, $right, $top, $bottom] = [$x + $cols[0], $x + $cols[\count($cols) - 1], $y + $rows[0], $y + $rows[\count($rows) - 1]];
        $marks = [];
        if ('+' === $strategy || '^' === $strategy) {
            \array_push(
                $marks,
                SaltPen::line($left, $top, $right, $top, self::GREY),
                SaltPen::line($left, $bottom, $right, $bottom, self::GREY),
                SaltPen::line($left, $top, $left, $bottom, self::GREY),
                SaltPen::line($right, $top, $right, $bottom, self::GREY),
            );
        }
        if ('-' === $strategy || '#' === $strategy) {
            foreach ($rows as $row) {
                $marks[] = SaltPen::line($left, $y + $row, $right, $y + $row, self::GREY);
            }
        }
        if ('!' === $strategy || '#' === $strategy) {
            foreach ($cols as $col) {
                $marks[] = SaltPen::line($x + $col, $top, $x + $col, $bottom, self::GREY);
            }
        }

        return $marks;
    }

    /** The widest entry, with its indent. */
    private function first(): float
    {
        return \array_reduce($this->entries, static fn (float $width, array $entry): float => \max($width, ($entry[0] * 10) + $entry[1]->size()[0]), initial: 0.0);
    }

    /** @return list<float> each other column's width, as wide as its widest cell */
    private function others(): array
    {
        $widths = [];
        foreach ($this->entries as [, , $cells]) {
            foreach ($cells as $index => $cell) {
                $widths[$index] = \max($widths[$index] ?? 0.0, $cell->size()[0]);
            }
        }

        return \array_values($widths);
    }

    /** @param array{int, SaltText, list<SaltElement>} $entry */
    private function rowHeight(array $entry): float
    {
        return \array_reduce($entry[2], static fn (float $height, SaltElement $cell): float => \max($height, $cell->size()[1]), initial: $entry[1]->size()[1]);
    }
}
