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
        $marks = self::frame($box, $this->border, DotColors::resolve($this->attributes['bgcolor'] ?? '', 'none'), $this->color);
        foreach ($this->cells as $index => $cell) {
            $cellBox = $this->box($index, $box);
            $color = DotColors::resolve($cell->attributes['color'] ?? '', $this->color);
            \array_push($marks, ...self::frame($cellBox, $cell->border, DotColors::resolve($cell->attributes['bgcolor'] ?? '', 'none'), $color));
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
     * The `<HR/>` and `<VR/>` rules, each down the middle of the spacing it stands in.
     *
     * @param array{float, float, float, float} $table
     * @return list<Mark>
     */
    private function rules(array $table): array
    {
        [$left, $top, $right] = $table;
        $marks = [];
        foreach (\array_keys($this->ruledRows) as $row) {
            $y = $top + $this->offset($this->heights, $row + 1) - ($this->spacing / 2);
            $marks[] = new Mark(MarkKind::Polyline, [[$left + $this->border, $y], [$right - $this->border, $y]], stroke: $this->color, strokeWidth: 1.0);
        }
        foreach ($this->cells as $index => $cell) {
            [$row, $column, $place] = $this->at[$index];
            if (!\array_key_exists($place, $this->ruledCells[$row] ?? [])) {
                continue;
            }
            $x = $left + $this->offset($this->widths, $column + $cell->colspan) - ($this->spacing / 2);
            $box = $this->box($index, $table);
            $marks[] = new Mark(MarkKind::Polyline, [[$x, $box[1]], [$x, $box[3]]], stroke: $this->color, strokeWidth: 1.0);
        }

        return $marks;
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
     * A box's fill and its border, drawn inside it.
     *
     * @param array{float, float, float, float} $box
     * @return list<Mark>
     */
    private static function frame(array $box, float $border, string $fill, string $color): array
    {
        if ($border <= 0 && 'none' === $fill) {
            return [];
        }
        $inset = $border / 2;
        [$left, $top, $right, $bottom] = [$box[0] + $inset, $box[1] + $inset, $box[2] - $inset, $box[3] - $inset];

        return [new Mark(
            MarkKind::Polygon,
            [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]],
            stroke: $border > 0 ? $color : 'none',
            fill: $fill,
            strokeWidth: \max($border, 0.01),
        )];
    }
}
