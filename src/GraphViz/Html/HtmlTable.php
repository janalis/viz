<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\Drawing\DotColors;
use Janalis\Viz\GraphViz\Drawing\DotLabel;
use Janalis\Viz\GraphViz\Drawing\Justify;

/**
 * An HTML label's `<TABLE>`, laid out as DOT documents it: cells in a grid, each row's cells in
 * the first columns their rows above leave free, spanning `COLSPAN` columns and `ROWSPAN`
 * rows. A column is as wide as its widest cell, a cell spanning several widening them evenly
 * when they fall short; rows likewise. `CELLSPACING` stands between cells and around them,
 * inside the table's `BORDER`; `<HR/>` and `<VR/>` rule the spacing between rows and cells.
 */
final class HtmlTable implements DotLabel
{
    /** @var list<array{int, int, int}> each cell's row and column, and its place among its row's cells, in the order of `$cells` */
    private array $at = [];
    /** @var list<HtmlCell> */
    private array $cells = [];
    /** @var list<float> */
    private array $widths = [];
    /** @var list<float> */
    private array $heights = [];

    /**
     * @param list<list<HtmlCell>> $rows
     * @param array<string, string> $attributes by lowercase name
     * @param array<int, true> $ruledRows the rows an `<HR/>` follows
     * @param array<int, array<int, true>> $ruledCells by row, the cells a `<VR/>` follows
     */
    public function __construct(
        array $rows,
        public readonly array $attributes,
        public readonly float $border,
        public readonly float $spacing,
        public readonly string $color,
        private readonly array $ruledRows = [],
        private readonly array $ruledCells = [],
    ) {
        $taken = [];
        foreach ($rows as $row => $cells) {
            $column = 0;
            foreach ($cells as $place => $cell) {
                while (\array_key_exists($row.':'.$column, $taken)) {
                    ++$column;
                }
                $this->at[] = [$row, $column, $place];
                $this->cells[] = $cell;
                for ($down = 0; $down < $cell->rowspan; ++$down) {
                    for ($across = 0; $across < $cell->colspan; ++$across) {
                        $taken[($row + $down).':'.($column + $across)] = true;
                    }
                }
                $column += $cell->colspan;
            }
        }
        $this->widths = $this->stretched($this->spread(axis: 1), 'width');
        $this->heights = $this->stretched($this->spread(axis: 0), 'height');
    }

    #[\Override]
    public function empty(): bool
    {
        return false;
    }

    #[\Override]
    public function size(): array
    {
        return [$this->extent($this->widths), $this->extent($this->heights)];
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $box = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
        $marks = self::frame($box, $this->border, DotColors::resolve($this->attributes['bgcolor'] ?? '', 'none'), $this->color, $this->attributes['sides'] ?? '');
        foreach ($this->cells as $index => $cell) {
            $cellBox = $this->box($index, $box);
            $color = DotColors::resolve($cell->attributes['color'] ?? '', $this->color);
            \array_push($marks, ...self::frame($cellBox, $cell->border, DotColors::resolve($cell->attributes['bgcolor'] ?? '', 'none'), $color, $cell->attributes['sides'] ?? ''));
            [$contentX, $contentY] = self::placed($cell, $cellBox);
            $content = $cell->content instanceof HtmlText ? $cell->content->justified(self::justify($cell->attributes['balign'] ?? '')) : $cell->content;
            \array_push($marks, ...$content->marks($contentX, $contentY));
        }
        \array_push($marks, ...$this->rules($box));

        return $marks;
    }

    #[\Override]
    public function ports(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $box = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
        $ports = [];
        foreach ($this->cells as $index => $cell) {
            $cellBox = $this->box($index, $box);
            [$contentX, $contentY] = self::placed($cell, $cellBox);
            $ports += $cell->content->ports($contentX, $contentY);
            $port = $cell->attributes['port'] ?? '';
            if ('' !== $port) {
                $ports[$port] ??= $cellBox;
            }
        }
        $own = $this->attributes['port'] ?? '';

        return '' === $own ? $ports : [$own => $box] + $ports;
    }

    /**
     * Each column's width, by axis 1, or row's height, by axis 0: as much as its cells need, a
     * cell spanning several sharing what they lack evenly.
     *
     * @return list<float>
     */
    private function spread(int $axis): array
    {
        $order = \array_keys($this->cells);
        $span = fn (int $index): int => 1 === $axis ? $this->cells[$index]->colspan : $this->cells[$index]->rowspan;
        \usort($order, static fn (int $a, int $b): int => $span($a) <=> $span($b));
        $sizes = [];
        foreach ($order as $index) {
            $first = $this->at[$index][$axis];
            $count = $span($index);
            $have = ($count - 1) * $this->spacing;
            for ($line = $first; $line < ($first + $count); ++$line) {
                $sizes[$line] ??= 0.0;
                $have += $sizes[$line];
            }
            $lack = $this->cells[$index]->size()[1 - $axis] - $have;
            for ($line = $first; $lack > 0 && $line < ($first + $count); ++$line) {
                $sizes[$line] += $lack / $count;
            }
        }
        $all = [];
        for ($line = 0, $last = [] === $sizes ? -1 : \max(\array_keys($sizes)); $line <= $last; ++$line) {
            $all[] = $sizes[$line] ?? 0.0;
        }

        return $all;
    }

    /**
     * Columns widened, or rows heightened, evenly to the table's `WIDTH` or `HEIGHT`.
     *
     * @param list<float> $sizes
     * @return list<float>
     */
    private function stretched(array $sizes, string $name): array
    {
        $asked = $this->attributes[$name] ?? '';
        $lack = \is_numeric($asked) ? \min(65_535.0, (float) $asked) - $this->extent($sizes) : 0.0;
        if ($lack <= 0 || [] === $sizes) {
            return $sizes;
        }

        return \array_map(static fn (float $size): float => $size + ($lack / \count($sizes)), $sizes);
    }

    /** @param list<float> $sizes */
    private function extent(array $sizes): float
    {
        return \array_sum($sizes) + ((\count($sizes) + 1) * $this->spacing) + (2 * $this->border);
    }

    /**
     * Where a line of cells starts, from the table's edge.
     *
     * @param list<float> $sizes
     */
    private function offset(array $sizes, int $line): float
    {
        return $this->border + $this->spacing + \array_sum(\array_slice($sizes, offset: 0, length: $line)) + ($line * $this->spacing);
    }

    /**
     * @param array{float, float, float, float} $table
     * @return array{float, float, float, float}
     */
    private function box(int $index, array $table): array
    {
        [$row, $column] = $this->at[$index];
        $cell = $this->cells[$index];
        $left = $table[0] + $this->offset($this->widths, $column);
        $top = $table[1] + $this->offset($this->heights, $row);

        return [
            $left,
            $top,
            $table[0] + $this->offset($this->widths, $column + $cell->colspan) - $this->spacing,
            $table[1] + $this->offset($this->heights, $row + $cell->rowspan) - $this->spacing,
        ];
    }

    /**
     * The rules between cells, each down the middle of the spacing it stands in: right of a cell
     * a `<VR/>` follows, or of every cell when `COLUMNS="*"`; below a cell whose last row an
     * `<HR/>` follows, or below every cell when `ROWS="*"`. A rule runs along its cell and half
     * the spacing past it, or to the table's border at its edge; none runs along the border.
     *
     * @param array{float, float, float, float} $table
     * @return list<Mark>
     */
    private function rules(array $table): array
    {
        $inset = $this->border / 2;
        [$first, $top, $last, $bottom] = [$table[0] + $inset, $table[1] + $inset, $table[2] - $inset, $table[3] - $inset];
        $half = $this->spacing / 2;
        $everyColumn = '*' === ($this->attributes['columns'] ?? '');
        $everyRow = '*' === ($this->attributes['rows'] ?? '');
        $marks = [];
        foreach ($this->cells as $index => $cell) {
            [$row, $column, $place] = $this->at[$index];
            $box = $this->box($index, $table);
            $after = $column + $cell->colspan;
            $below = $row + $cell->rowspan;
            if ($after < \count($this->widths) && ($everyColumn || \array_key_exists($place, $this->ruledCells[$row] ?? []))) {
                $x = $box[2] + $half;
                $marks[] = $this->rule([[$x, 0 === $row ? $top : $box[1] - $half], [$x, $below < \count($this->heights) ? $box[3] + $half : $bottom]]);
            }
            if ($below < \count($this->heights) && ($everyRow || \array_key_exists($below - 1, $this->ruledRows))) {
                $y = $box[3] + $half;
                $marks[] = $this->rule([[0 === $column ? $first : $box[0] - $half, $y], [$after < \count($this->widths) ? $box[2] + $half : $last, $y]]);
            }
        }

        return $marks;
    }

    /** @param list<array{float, float}> $points */
    private function rule(array $points): Mark
    {
        return new Mark(MarkKind::Polyline, $points, stroke: $this->color, strokeWidth: 1.0);
    }

    /**
     * Where a cell's content stands: as its `ALIGN` and `VALIGN` set it within the cell's padding and border.
     *
     * @param array{float, float, float, float} $box
     * @return array{float, float}
     */
    private static function placed(HtmlCell $cell, array $box): array
    {
        [$width, $height] = $cell->content->size();
        $inset = $cell->padding + $cell->border;
        [$left, $top, $right, $bottom] = [$box[0] + $inset, $box[1] + $inset, $box[2] - $inset, $box[3] - $inset];
        $x = match (\strtolower($cell->attributes['align'] ?? '')) {
            'left' => $left + ($width / 2),
            'right' => $right - ($width / 2),
            default => ($left + $right) / 2,
        };
        $y = match (\strtolower($cell->attributes['valign'] ?? '')) {
            'top' => $top + ($height / 2),
            'bottom' => $bottom - ($height / 2),
            default => ($top + $bottom) / 2,
        };

        return [$x, $y];
    }

    private static function justify(string $align): Justify
    {
        return match (\strtolower($align)) {
            'left' => Justify::Left,
            'right' => Justify::Right,
            default => Justify::Center,
        };
    }

    /**
     * A box's fill and its border, drawn inside it: all around, or along the sides `SIDES`
     * names — `L`, `T`, `R` and `B` — each run of adjacent sides one line.
     *
     * @param array{float, float, float, float} $box
     * @return list<Mark>
     */
    private static function frame(array $box, float $border, string $fill, string $color, string $sides): array
    {
        if ($border <= 0 && 'none' === $fill) {
            return [];
        }
        $inset = $border / 2;
        [$left, $top, $right, $bottom] = [$box[0] + $inset, $box[1] + $inset, $box[2] - $inset, $box[3] - $inset];
        $corners = [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]];
        // Each side runs from its corner to the next: top, right, bottom, left.
        $drawn = '' === $sides ? [true, true, true, true] : \array_map(static fn (string $side): bool => \str_contains(\strtolower($sides), $side), ['t', 'r', 'b', 'l']);
        $whole = $border > 0 && !\in_array(false, $drawn, strict: true);
        $marks = $whole || 'none' !== $fill ? [new Mark(MarkKind::Polygon, $corners, stroke: $whole ? $color : 'none', fill: $fill, strokeWidth: \max($border, 0.01))] : [];
        if ($whole || $border <= 0) {
            return $marks;
        }
        foreach ($drawn as $side => $on) {
            if (!$on || $drawn[($side + 3) % 4]) {
                continue;
            }
            $run = [$corners[$side]];
            for ($next = $side; $drawn[$next]; $next = ($next + 1) % 4) {
                $run[] = $corners[($next + 1) % 4];
            }
            $marks[] = new Mark(MarkKind::Polyline, $run, stroke: $color, strokeWidth: $border);
        }

        return $marks;
    }
}
