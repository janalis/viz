<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Text\FontFace;

/**
 * A Creole table, as PlantUML's `StripeTable` draws one: rows of `|`-separated cells, a cell
 * opening with `=` a bold header and one opening with `<#colour>` filled with it, as is a row
 * opening with it before its first `|`; each column as wide as its widest cell and each row as
 * tall as its tallest, with no room around their text; a line of 1 between and around them
 * all, of the text's colour or the second a row's first gives; and 2 above and below.
 */
final readonly class PlantUmlTable
{
    private const float MARGIN = 2.0;

    /** @param list<list<array{PlantUmlText, ?string}>> $rows each cell's text, and its colour */
    private function __construct(
        private array $rows,
        private string $line,
    ) {}

    /**
     * @param list<string> $lines a row each, `|` at its start and its end
     * @param PlantUmlText $text the text the table stands in, whose font, colour and sprites its cells take
     */
    public static function of(array $lines, PlantUmlText $text): self
    {
        $rows = [];
        $color = $text->color;
        foreach ($lines as $index => $line) {
            $cells = [];
            $prefix = [];
            \preg_match('/^<#(\w+)(?:,#?(\w+))?>/', \trim($line), $prefix);
            $color = 0 === $index && '' !== ($prefix[2] ?? '') ? PlantUmlColor::parse($prefix[2]) ?? $color : $color;
            $rowBack = '' === ($prefix[1] ?? '') ? null : PlantUmlColor::parse($prefix[1]);
            foreach (\explode('|', \substr(\trim(\substr(\trim($line), \strlen($prefix[0] ?? ''))), offset: 1, length: -1)) as $cell) {
                $header = \str_starts_with($cell, '=');
                $cell = $header ? \substr($cell, offset: 1) : $cell;
                $match = [];
                $back = (1 === \preg_match('/^\s*<#(\w+)(?:,#?\w+)?>/', $cell, $match) ? PlantUmlColor::parse($match[1]) : null) ?? $rowBack;
                $cell = [] === $match ? $cell : \substr($cell, \strlen($match[0]));
                $cells[] = [new PlantUmlText([$cell], $text->size, $header ? FontFace::Bold : $text->face, $text->color, $text->sprites, $back ?? $text->background), $back];
            }
            $rows[] = $cells;
        }

        return new self($rows, $color);
    }

    /** Whether a line is a row: `|` at its start, or after a colour, and at its end. */
    public static function row(string $line): bool
    {
        return 1 === \preg_match('/^(?:<#[^>]+>)?\|.*\|$/', \trim($line));
    }

    /** @return array{float, float} */
    public function size(): array
    {
        return [\array_sum($this->widths()), \array_sum($this->heights()) + (2 * self::MARGIN)];
    }

    /** @return list<Mark> its cells' colours, its text, and its lines, from its top left */
    public function marks(float $x, float $y): array
    {
        [$widths, $heights] = [$this->widths(), $this->heights()];
        [$width, $height] = [\array_sum($widths), \array_sum($heights)];
        $y += self::MARGIN;
        $marks = [];
        $top = $y;
        $lines = [[[$x, $y], [$x + $width, $y]]];
        foreach ($this->rows as $index => $row) {
            $left = $x;
            foreach ($row as $column => [$text, $back]) {
                $right = $left + $widths[$column];
                if (null !== $back) {
                    $marks[] = new Mark(MarkKind::Polygon, [[$left, $top], [$right, $top], [$right, $top + $heights[$index]], [$left, $top + $heights[$index]]], stroke: 'none', fill: $back);
                }
                \array_push($marks, ...$text->marks($left, $top, $widths[$column], 'left'));
                $left = $right;
            }
            $top += $heights[$index];
            $lines[] = [[$x, $top], [$x + $width, $top]];
        }
        $left = $x;
        $lines[] = [[$left, $y], [$left, $y + $height]];
        foreach ($widths as $columnWidth) {
            $left += $columnWidth;
            $lines[] = [[$left, $y], [$left, $y + $height]];
        }
        foreach ($lines as $line) {
            $marks[] = new Mark(MarkKind::Polyline, $line, stroke: $this->line, strokeWidth: 1.0);
        }

        return $marks;
    }

    /** @return list<float> each column's width: its widest cell's */
    private function widths(): array
    {
        $widths = [];
        foreach ($this->rows as $row) {
            foreach ($row as $column => [$text]) {
                $widths[$column] = \max($widths[$column] ?? 0.0, $text->size()[0]);
            }
        }

        return \array_values($widths);
    }

    /** @return list<float> each row's height: its tallest cell's */
    private function heights(): array
    {
        return \array_map(static fn (array $row): float => \max(\array_map(static fn (array $cell): float => \max($cell[0]->size()[1], PlantUmlText::lineHeight($cell[0]->size)), $row)), $this->rows);
    }
}
