<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Text\DisplayWidth;

/**
 * An object or an array as PlantUML's `TextBlockJson` draws it: a row for each member, its
 * key in bold in a first column, its value in a second — a container's as three spaces —
 * or a row for each item, its value alone; each cell its text 5 in from either side and 2
 * above and below, without Creole; a highlighted row's back filled; lines between rows and
 * columns, in a rounded box.
 */
final class JsonTable
{
    private const float MIN_WIDTH = 30.0;
    private const float MIN_HEIGHT = 15.0;

    /** @var list<array{?array{list<string>, Style}, array{list<string>, Style}, ?JsonHighlight}> each row's key, value and highlight */
    private array $rows = [];

    /** @param list<JsonHighlight> $highlights those that reach its keys */
    public function __construct(
        public readonly JsonNode $node,
        private readonly StyleSheet $sheet,
        private readonly string $diagram,
        public readonly array $highlights,
    ) {
        $object = JsonKind::Object === $node->kind;
        foreach ($node->members as [$key, $value]) {
            $highlight = self::highlight($key, $highlights);
            $this->rows[] = [
                $object ? [self::lines($key), $this->style(['header', 'node'], $highlight)] : null,
                [self::lines($value->short()), $this->style(['node'], $highlight)],
                $highlight,
            ];
        }
    }

    /** @return array{float, float} */
    public function size(): array
    {
        $height = \array_sum($this->heights());

        return [$this->widthA() + $this->widthB(), 0.0 === $height ? self::MIN_HEIGHT : $height];
    }

    /** @return list<float> each row's height */
    public function heights(): array
    {
        return \array_map(fn (array $row): float => \max(null === $row[0] ? 0.0 : $this->cell(...$row[0])[1], $this->cell(...$row[1])[1]), $this->rows);
    }

    /** The first column's width: the widest key, or the widest value of an array. */
    public function widthA(): float
    {
        return \array_reduce($this->rows, fn (float $width, array $row): float => \max($width, $this->cell(...$row[0] ?? $row[1])[0]), initial: 0.0);
    }

    /** The second column's width: the widest value of an object. */
    public function widthB(): float
    {
        return \array_reduce($this->rows, fn (float $width, array $row): float => null === $row[0] ? $width : \max($width, $this->cell(...$row[1])[0]), initial: 0.0);
    }

    /**
     * Each row's container, and the highlights that reach under its key, as PlantUML's
     * `upOneLevel` passes them down.
     *
     * @return array<int, array{JsonNode, list<JsonHighlight>}> by row
     */
    public function children(): array
    {
        $children = [];
        foreach ($this->node->members as $row => [$key, $value]) {
            if (!$value->container()) {
                continue;
            }
            $children[$row] = [$value, \array_values(\array_filter(\array_map(static fn (JsonHighlight $highlight): ?JsonHighlight => $highlight->under($key), $this->highlights)))];
        }

        return $children;
    }

    /** @return list<Mark> its top left at a point */
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $width = 0.0 === $width ? self::MIN_WIDTH : $width;
        $node = $this->sheet->style(['root', 'element', $this->diagram, 'node']);
        $separator = $this->sheet->style(['root', 'element', $this->diagram, 'node', 'separator']);
        $round = $node->number('RoundCorner');
        $back = $node->color('BackGroundColor', '#f1f1f1');
        $marks = [ClassBox::rectangle($x, $y, $width, $height, $back, $back, $node->number('LineThickness', 1.5), $round)];
        $widthA = $this->widthA();
        $widthB = $this->widthB();
        $align = \strtolower($node->value('HorizontalAlignment', 'left'));
        $top = $y;
        foreach ($this->heights() as $index => $rowHeight) {
            [$key, $value, $highlight] = $this->rows[$index];
            if (null !== $highlight) {
                $fill = $this->sheet->style(['root', 'element', $this->diagram, 'node', 'highlight'], $highlight->stereotypes())->color('BackGroundColor', '#ccff02');
                $marks[] = ClassBox::rectangle($x + 1.5, $top, $width - 2, $rowHeight, $fill, $fill, 1.0, 4.0);
            }
            if ($top > $y) {
                \array_push($marks, ...self::line($separator, [$x, $top], [$x + $width, $top]));
            }
            \array_push($marks, ...$this->cellMarks($key ?? $value, $x, $top, $widthA, $align));
            if (null !== $key) {
                \array_push($marks, ...$this->cellMarks($value, $x + $widthA, $top, $widthB, $align), ...self::line($separator, [$x + $widthA, $top], [$x + $widthA, $top + $rowHeight]));
            }
            $top += $rowHeight;
        }
        $marks[] = ClassBox::rectangle($x, $y, $width, $height, $node->color('LineColor', '#000000'), 'none', $node->number('LineThickness', 1.5), $round);

        return $marks;
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return list<Mark> a line in a style, none in a transparent one
     */
    public static function line(Style $style, array $from, array $to): array
    {
        $color = $style->color('LineColor', '#000000');

        return (
            'none' === $color
                ? []
                : [new Mark(MarkKind::Polyline, [$from, $to], stroke: $color, strokeWidth: $style->number('LineThickness', 1.0), dashed: null !== $style->dashes(), dashes: $style->dashes())]
        );
    }

    /**
     * A cell's text, its lines as PlantUML's `Display` splits them at `\n` written out; an
     * empty one a space. A newline or a tab within one, which PlantUML writes as it is, shows
     * as a space.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return \array_map(static fn (string $line): string => '' === $line ? ' ' : \strtr($line, from: "\n\r\t", to: '   '), \explode('\n', $text));
    }

    /**
     * @param list<string> $lines
     * @return array{float, float} a cell's size: its text's, 5 more either side and 2 above and below
     */
    private function cell(array $lines, Style $style): array
    {
        $size = $style->fontSize();
        $width = \array_reduce($lines, static fn (float $widest, string $line): float => \max($widest, DisplayWidth::of($line) * Units::cell($size)), initial: 0.0);

        return [$width + 10, (\count($lines) * PlantUmlText::lineHeight($size)) + 4];
    }

    /**
     * @param array{list<string>, Style} $cell
     * @return list<Mark> a cell's text, set in a column as its style aligns it
     */
    private function cellMarks(array $cell, float $x, float $y, float $column, string $align): array
    {
        [$lines, $style] = $cell;
        [$width] = $this->cell($lines, $style);
        $x += match ($align) {
            'right' => $column - $width,
            'center' => ($column - $width) / 2,
            default => 0.0,
        };
        $size = $style->fontSize();
        $line = PlantUmlText::lineHeight($size);
        $color = $style->color('FontColor', '#000000');
        $marks = [];
        foreach ($lines as $index => $text) {
            if ('' === \trim($text) || 'none' === $color) {
                continue;
            }
            $foot = $y + 2 + (($index + 1) * $line);
            $marks[] = new Mark(
                MarkKind::Text,
                [[$x + 5 + ((DisplayWidth::of($text) * Units::cell($size)) / 2), $foot - (0.25 * $line)]],
                $text,
                stroke: $color,
                font: $style->face(),
                fontScale: Units::fontScale($size) / Units::POINT,
            );
        }

        return $marks;
    }

    /** @param list<JsonHighlight> $highlights */
    private static function highlight(string $key, array $highlights): ?JsonHighlight
    {
        foreach ($highlights as $highlight) {
            if ($highlight->highlights($key)) {
                return $highlight;
            }
        }

        return null;
    }

    /**
     * As PlantUML's `getStyleToUse`: a key's or a value's, highlighted or not.
     *
     * @param list<string> $names `header` and `node` for a key, `node` for a value
     */
    private function style(array $names, ?JsonHighlight $highlight): Style
    {
        $names = ['root', 'element', $this->diagram, ...$names, ...(null === $highlight ? [] : ['highlight'])];

        return $this->sheet->style($names, $highlight?->stereotypes() ?? []);
    }
}
