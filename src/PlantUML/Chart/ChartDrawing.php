<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A chart drawn as PlantUML's `ChartRenderer` draws it: a plot 300 high and 60 wide for each
 * category, 400 at least, 20 in from the edges, with 40 left for the numeric axis' labels and
 * 30 above; its axes — crossing at zero where the ranges hold it — then the grid lines, the
 * series, the annotations and the legend. A chart laid on its side takes its categories down
 * the left and its `h-axis` range across the foot.
 */
final class ChartDrawing
{
    private const float MARGIN = 20.0;
    private const float AXIS = 40.0;
    private const float TITLE = 30.0;
    private const float HEIGHT = 300.0;
    private const float TICK = 5.0;
    private const float LEGEND_MARGIN = 10.0;
    private const float SYMBOL = 12.0;
    private const float SYMBOL_GAP = 5.0;
    private const float ITEM_GAP = 15.0;

    /** @var list<Mark> */
    private array $marks = [];
    private readonly float $width;
    /** @var array{float, float} how far right and down the text reaches, as PlantUML's SVG writer measures it: unturned, to its baseline */
    private array $reach = [0.0, 0.0];

    /**
     * @param list<string> $labels the categories, across or, on its side, down
     * @param array{bool, bool} $grids whether grid lines run up from the across axis, and across from the numeric one
     */
    private function __construct(
        public readonly PlantUmlChart $chart,
        public readonly array $labels,
        private readonly string $title,
        private readonly ?int $spacing,
        public readonly ChartAxis $xAxis,
        public readonly ChartAxis $yAxis,
        public readonly ?ChartAxis $y2Axis,
        private readonly array $grids,
    ) {
        $this->width = \max(400.0, \count($labels) * 60.0);
    }

    /** As `ChartDiagram`'s `getRenderer`: on its side, the `v-axis` categories and the `h-axis` range take the across and numeric axes' parts. */
    public static function draw(PlantUmlChart $chart): Drawing
    {
        $drawing = $chart->horizontal
            ? new self($chart, $chart->yLabels, $chart->yAxis->title, null, $chart->xAxis, $chart->xAxis, null, [$chart->yGrid, $chart->xGrid])
            : new self($chart, $chart->xLabels, '' !== $chart->xAxis->title ? $chart->xAxis->title : (string) $chart->xTitle, $chart->xSpacing, $chart->xAxis, $chart->yAxis, $chart->y2Axis, [
                $chart->xGrid,
                $chart->yGrid,
            ]);

        return $drawing->paint();
    }

    /** The style of a series, or of a kind of series, its stereotypes taken. */
    public function style(?ChartSeries $series, ChartKind $kind = ChartKind::Bar): Style
    {
        if (null === $series) {
            return $this->part([$kind->value]);
        }

        return $this->part([$series->kind->value], $series->stereotypes);
    }

    /** A scatter series' marker: the style's, else the series' own. */
    public function shape(ChartSeries $series): string
    {
        $shape = \strtolower(\trim($this->style($series)->value('MarkerShape')));

        return \in_array($shape, ['circle', 'square', 'triangle'], strict: true) ? $shape : $series->marker;
    }

    private function paint(): Drawing
    {
        [$legendWidth, $legendHeight] = $this->legendSize();
        $width = (2 * self::MARGIN) + self::AXIS + $this->width + (null === $this->y2Axis ? 0.0 : self::AXIS);
        $height = (2 * self::MARGIN) + self::TITLE + self::HEIGHT + self::AXIS + ('' === $this->title ? 0.0 : 20.0);
        $left = self::MARGIN + self::AXIS;
        $top = self::MARGIN + self::TITLE;
        $legend = $this->chart->legend;
        if (\in_array($legend, ['left', 'right'], strict: true)) {
            $width += $legendWidth + self::LEGEND_MARGIN;
            $left += 'left' === $legend ? $legendWidth + self::LEGEND_MARGIN : 0.0;
        }
        if (\in_array($legend, ['top', 'bottom'], strict: true)) {
            $height += $legendHeight + self::LEGEND_MARGIN;
            $top += 'top' === $legend ? $legendHeight + self::LEGEND_MARGIN : 0.0;
        }
        $this->axes($left, $top);
        $this->gridLines($left, $top);
        \array_push($this->marks, ...new ChartPlot($this, $left, $top, $this->width, self::HEIGHT)->marks());
        foreach ($this->chart->annotations as $annotation) {
            $this->annotation($annotation, $left, $top);
        }
        $this->legend($left, $top);
        // As PlantUML's SVG writer, which leaves out what stands at no number, as on a flat axis.
        $this->marks = \array_values(\array_filter(
            $this->marks,
            static fn (Mark $mark): bool => [] === \array_filter($mark->points, static fn (array $point): bool => !\is_finite($point[0]) || !\is_finite($point[1])),
        ));
        // As PlantUML's SVG writer, which makes room for whatever it draws past the chart's size, 10 in from its margin.
        foreach ($this->marks as $mark) {
            foreach (MarkKind::Text === $mark->kind ? [] : $mark->points as [$x, $y]) {
                $this->reach = [\max($this->reach[0], $x), \max($this->reach[1], $y)];
            }
        }

        // As PlantUML sizes a chart, as measured on its output: rounded down, and 1 more.
        return PlantUmlFrame::roundedDown($this->chart->document, $this->marks, \max($width, $this->reach[0] - 10), \max($height, $this->reach[1] - 10), past: 1.0);
    }

    /**
     * @param list<string> $names
     * @param list<string> $stereotypes
     */
    private function part(array $names, array $stereotypes = []): Style
    {
        return $this->chart->document->styles->style(['root', 'element', 'chartDiagram', ...$names], $stereotypes);
    }

    private function axes(float $left, float $top): void
    {
        if ($this->chart->horizontal) {
            $this->side($left, $top);
            $this->foot($left, $top + self::HEIGHT);

            return;
        }
        $across = $top + self::HEIGHT;
        if ($this->yAxis->min <= 0 && $this->yAxis->max >= 0) {
            $across = $top + (self::HEIGHT * (1.0 - $this->yAxis->ratio(0.0)));
        }
        $up = $this->xAxis->min <= 0 && $this->xAxis->max >= 0 ? $left + $this->xAxis->pixel(0.0, 0.0, $this->width) : $left;
        $this->numeric($this->yAxis, $up, $top, -1.0);
        if (null !== $this->y2Axis) {
            $this->numeric($this->y2Axis, $left + $this->width, $top, 1.0);
        }
        $this->across($left, $across);
    }

    /** As `drawYAxis`: an upright numeric axis, its ticks and labels on one side, then its title. */
    private function numeric(ChartAxis $axis, float $x, float $top, float $side): void
    {
        $style = $this->part(['axis', 'vAxis']);
        $this->line([$x, $top], [$x, $top + self::HEIGHT], $style);
        foreach (self::ticks($axis) as [$y, $label]) {
            $this->line([$x, $top + $y], [$x + ($side * self::TICK), $top + $y], $style);
            $text = ChartText::styled($label, $style);
            $this->text($text, $side < 0 ? $x - self::TICK - $text->width() - 5 : $x + self::TICK + 5, $top + $y - ($text->height() / 2), 'right');
        }
        if ('' === $axis->title) {
            return;
        }
        if ($axis->top) {
            $text = ChartText::styled($axis->title, $style);
            $this->text($text, $x + (($side * self::AXIS) / 2) - ($text->width() / 2), $top - $text->height() - 15, 'center');

            return;
        }
        $this->turned($axis->title, $style, $x, $top, $side);
    }

    /** @return list<array{float, string}> each tick's place down the axis and its label: those named, else every spacing, else six evenly */
    private static function ticks(ChartAxis $axis): array
    {
        if ([] !== $axis->ticks) {
            $ticks = \array_map(static fn (array $tick): array => [self::HEIGHT * (1.0 - $axis->ratio($tick[0])), $tick[1]], $axis->ticks);

            return \array_values(\array_filter($ticks, static fn (array $tick): bool => $tick[0] >= 0 && $tick[0] <= self::HEIGHT));
        }
        if (null !== $axis->spacing) {
            $values = self::steps($axis->min, $axis->max, $axis->spacing, \ceil(\fdiv($axis->min, $axis->spacing)) * $axis->spacing);

            return \array_map(static fn (float $value): array => [self::HEIGHT * (1.0 - $axis->ratio($value)), ChartText::value($value)], $values);
        }
        $ticks = [];
        for ($tick = 0; $tick <= 5; ++$tick) {
            $ticks[] = [self::HEIGHT * (1.0 - ($tick / 5)), ChartText::value($axis->min + ((($axis->max - $axis->min) * $tick) / 5))];
        }

        return $ticks;
    }

    /**
     * As `drawVerticalText`: a title along the axis, turned to read up on the left and down on
     * the right, centred on it, 10 further out than the labels' room.
     */
    private function turned(string $title, Style $style, float $x, float $top, float $side): void
    {
        $size = $style->fontSize();
        $width = PlantUmlMetrics::width($title, $size, $style->face());
        $height = new ChartText($title, $size)->height();
        $start = $x + ($side * (self::AXIS - ($height / 2) + 10));
        $this->reach = [\max($this->reach[0], $start + $width), \max($this->reach[1], $top + (self::HEIGHT / 2) - (($side * $width) / 2))];
        $this->marks[] = new Mark(
            MarkKind::Text,
            [[$start, $top + (self::HEIGHT / 2)]],
            $title,
            stroke: $style->color('FontColor', '#000000'),
            font: $style->face(),
            fontScale: Units::fontScale($size) / Units::POINT,
            angle: $side * 90,
        );
    }

    /** As `drawXAxis`: the across axis, its categories' ticks and labels — or a range's — then its title. */
    private function across(float $left, float $y): void
    {
        $style = $this->part(['axis', 'hAxis']);
        $this->line([$left, $y], [$left + $this->width, $y], $style);
        if ([] !== $this->labels) {
            $this->categories($left, $y, $style);
        }
        if ([] === $this->labels) {
            $interval = $this->interval();
            foreach (self::steps($this->xAxis->min, $this->xAxis->max, $interval, \floor(\fdiv($this->xAxis->min, $interval)) * $interval) as $value) {
                $x = $left + $this->xAxis->pixel($value, 0.0, $this->width);
                $this->line([$x, $y], [$x, $y + self::TICK], $style);
                $text = ChartText::styled(ChartText::value($value, 1), $style);
                $this->text($text, $x - ($text->width() / 2), $y + self::TICK + 5, 'center');
            }
        }
        if ('' === $this->title) {
            return;
        }
        $text = ChartText::styled($this->title, $style);
        if ($this->chart->labelRight) {
            $this->text($text, $left + $this->width + 10, $y - ($text->height() / 2), 'left');

            return;
        }
        $this->text($text, $left + ($this->width / 2) - ($text->width() / 2), $y + self::TICK + 25, 'center');
    }

    /** The categories' grid lines, up and down from the axis to the plot's edges, then a tick and label at every spacing's. */
    private function categories(float $left, float $y, Style $style): void
    {
        $column = $this->width / \count($this->labels);
        if ($this->grids[0]) {
            $grid = $this->part(['grid']);
            $crosses = $this->yAxis->min <= 0 && $this->yAxis->max >= 0;
            $up = $crosses ? self::HEIGHT * (1.0 - $this->yAxis->ratio(0.0)) : self::HEIGHT;
            $down = $crosses ? self::HEIGHT * $this->yAxis->ratio(0.0) : 0.0;
            foreach (\array_keys($this->labels) as $index) {
                $x = $left + (($index + 0.5) * $column);
                if ($up > 0) {
                    $this->line([$x, $y], [$x, $y - $up], $grid);
                }
                if ($down > 0) {
                    $this->line([$x, $y], [$x, $y + $down], $grid);
                }
            }
        }
        foreach ($this->labels as $index => $label) {
            if (0 !== ($index % \max(1, $this->spacing ?? 1))) {
                continue;
            }
            $x = $left + (($index + 0.5) * $column);
            $this->line([$x, $y], [$x, $y + self::TICK], $style);
            $text = ChartText::styled($label, $style);
            $this->text($text, $x - ($text->width() / 2), $y + self::TICK + 5, 'center');
        }
    }

    /** The across axis' tick interval: its spacing, else a tenth of its range. */
    private function interval(): float
    {
        return null !== $this->spacing && $this->spacing > 0 ? (float) $this->spacing : ($this->xAxis->max - $this->xAxis->min) / 10;
    }

    /** As `drawCategoriesVerticallyOnLeft`: on its side, the categories down the left, in 10-point text. */
    private function side(float $left, float $top): void
    {
        $style = $this->part([]);
        $this->line([$left, $top], [$left, $top + self::HEIGHT], $style, 1.0);
        foreach ($this->labels as $index => $label) {
            $y = $top + (($index + 0.5) * (self::HEIGHT / \count($this->labels)));
            $this->line([$left - self::TICK, $y], [$left, $y], $style, 1.0);
            $text = new ChartText($label, 10.0, color: $style->color('FontColor', '#000000'));
            $this->text($text, $left - self::TICK - $text->width() - 5, $y - ($text->height() / 2), 'right');
        }
    }

    /** As `drawYAxisHorizontally`: on its side, the range across the foot in six ticks, whole numbers in 10-point text. */
    private function foot(float $left, float $y): void
    {
        $style = $this->part([]);
        $color = $style->color('FontColor', '#000000');
        $this->line([$left, $y], [$left + $this->width, $y], $style, 1.0);
        $range = $this->yAxis->max - $this->yAxis->min;
        for ($tick = 0; $tick <= 5; ++$tick) {
            $x = $left + (($this->width * $tick) / 5);
            $this->line([$x, $y], [$x, $y + self::TICK], $style, 1.0);
            $text = new ChartText(\sprintf('%.0f', $this->yAxis->min + (($tick * $range) / 5)), 10.0, color: $color);
            $this->text($text, $x - ($text->width() / 2), $y + self::TICK + 5, 'center');
        }
        if ('' !== $this->yAxis->title) {
            $text = new ChartText($this->yAxis->title, 10.0, color: $color);
            $this->text($text, $left + ($this->width / 2) - ($text->width() / 2), $y + self::TICK + 25, 'center');
        }
    }

    /** As `drawGridLines`: up from the across axis' range ticks, and across at a tenth of the numeric range, or its spacing. */
    private function gridLines(float $left, float $top): void
    {
        $grid = $this->part(['grid']);
        if ($this->grids[0]) {
            $interval = $this->interval();
            foreach (self::steps($this->xAxis->min, $this->xAxis->max, $interval, \floor(\fdiv($this->xAxis->min, $interval)) * $interval) as $value) {
                $x = $left + $this->xAxis->pixel($value, 0.0, $this->width);
                $this->line([$x, $top], [$x, $top + self::HEIGHT], $grid);
            }
        }
        if (!$this->grids[1]) {
            return;
        }
        $axis = $this->yAxis;
        $interval = null !== $axis->spacing && $axis->spacing > 0 ? $axis->spacing : ($axis->max - $axis->min) / 10;
        foreach (self::steps($axis->min, $axis->max, $interval, \ceil(\fdiv($axis->min, $interval)) * $interval) as $value) {
            $y = $top + (self::HEIGHT * (1.0 - $axis->ratio($value)));
            $this->line([$left, $y], [$left + $this->width, $y], $grid);
        }
    }

    /** @return list<float> the values from a start by an interval, as far as a hundredth of it past the end, those a hundredth short of the beginning left out */
    private static function steps(float $min, float $max, float $interval, float $start): array
    {
        if (!($interval > 0) || !\is_finite($start)) {
            return [];
        }
        $values = [];
        for ($value = $start; $value <= ($max + ($interval * 0.01)) && \count($values) < 10_000; $value += $interval) {
            if ($value < ($min - ($interval * 0.01))) {
                continue;
            }
            $values[] = $value;
        }

        return $values;
    }

    /** As `drawAnnotations`: text at a point, or 40 above it — or below, wanting room — with an arrow to it. */
    private function annotation(ChartAnnotation $annotation, float $left, float $top): void
    {
        $point = $this->point($annotation);
        if (null === $point) {
            return;
        }
        [$x, $y] = [$left + $point[0], $top + $point[1]];
        $style = $this->part(['annotation']);
        $text = ChartText::styled($annotation->text, $style);
        [$width, $height] = [$text->width(), $text->height()];
        if (!$annotation->arrow) {
            $this->text($text, $x - ($width / 2), $y - $height - 5, 'left');

            return;
        }
        $above = $point[1] > ($height + 40);
        $textTop = $above ? $y - $height - 40 : $y + 40;
        $this->text($text, $x - ($width / 2), $textTop, 'left');
        $from = $above ? $textTop + $height + 5 : $textTop - 5;
        $to = $above ? $y - 5 : $y + 5;
        $color = $style->color('LineColor', '#000000');
        $this->line([$x, $from], [$x, $to], $style);
        $back = $above ? -8.0 : 8.0;
        $this->marks[] = new Mark(MarkKind::Polygon, [[$x, $to], [$x - 5, $to + $back], [$x + 5, $to + $back]], stroke: $color, fill: $color, strokeWidth: 1.0);
    }

    /** @return ?array{float, float} where an annotation points in the plot, or null for a category there is not */
    private function point(ChartAnnotation $annotation): ?array
    {
        $across = $this->chart->horizontal ? self::HEIGHT : $this->width;
        $index = \is_string($annotation->x) ? \array_search($annotation->x, $this->labels, strict: true) : null;
        if (false === $index) {
            return null;
        }
        $at = \is_float($annotation->x) ? $across * $this->yAxis->ratio($annotation->x) : ($across * ((int) $index + 0.5)) / \count($this->labels);
        if ($this->chart->horizontal) {
            return [$this->width * $this->yAxis->ratio($annotation->y), null === $index ? self::HEIGHT - $at : $at];
        }

        return [$at, self::HEIGHT * (1.0 - $this->yAxis->ratio($annotation->y))];
    }

    /** @return array{float, float} as `calculateLegendDimension`: the items down the side, or along the top or foot */
    private function legendSize(): array
    {
        if ('none' === $this->chart->legend || [] === $this->chart->series) {
            return [0.0, 0.0];
        }
        $style = $this->part(['legend']);
        $down = \in_array($this->chart->legend, ['left', 'right'], strict: true);
        [$width, $height] = [0.0, 0.0];
        foreach ($this->chart->series as $series) {
            $text = ChartText::styled($series->name, $style);
            $width = $down ? \max($width, $text->width()) : $width + $text->width() + self::SYMBOL + self::SYMBOL_GAP + self::ITEM_GAP;
            $height = $down ? $height + $text->height() + self::ITEM_GAP : \max($height, $text->height());
        }

        return $down ? [$width + self::SYMBOL + self::SYMBOL_GAP + (self::LEGEND_MARGIN * 2), $height] : [$width, $height + (self::LEGEND_MARGIN * 2)];
    }

    /** As `drawLegend`: each series' symbol, in its colour, and its name. */
    private function legend(float $left, float $top): void
    {
        if ('none' === $this->chart->legend || [] === $this->chart->series) {
            return;
        }
        [$x, $y] = match ($this->chart->legend) {
            'left' => [self::MARGIN, $top],
            'right' => [$left + $this->width + (null === $this->y2Axis ? 0.0 : self::AXIS) + self::LEGEND_MARGIN, $top],
            'top' => [$left, self::MARGIN],
            default => [$left, $top + self::HEIGHT + self::AXIS + self::LEGEND_MARGIN],
        };
        $style = $this->part(['legend']);
        foreach ($this->chart->series as $series) {
            $this->symbol($series, $x, $y);
            $text = ChartText::styled($series->name, $style);
            $this->text($text, $x + self::SYMBOL + self::SYMBOL_GAP, $y, 'left');
            if (\in_array($this->chart->legend, ['left', 'right'], strict: true)) {
                $y += $text->height() + self::ITEM_GAP;
                continue;
            }
            $x += self::SYMBOL + self::SYMBOL_GAP + $text->width() + self::ITEM_GAP;
        }
    }

    private function symbol(ChartSeries $series, float $x, float $y): void
    {
        $style = $this->style($series);
        $half = self::SYMBOL / 2;
        $this->marks[] = match ($series->kind) {
            ChartKind::Line => new Mark(MarkKind::Polyline, [[$x, $y + $half], [$x + self::SYMBOL, $y + $half]], stroke: $series->color ?? $style->color('LineColor', '#000000'), strokeWidth: 2.0),
            ChartKind::Scatter => ChartPlot::marker($this->shape($series), $style->color('MarkerColor', '#000000'), $x + $half, $y + $half, self::SYMBOL * 0.7),
            default => ClassBox::rectangle(
                $x,
                $y,
                self::SYMBOL,
                self::SYMBOL,
                $series->color ?? $style->color('BackGroundColor', '#000000'),
                $series->color ?? $style->color('BackGroundColor', '#000000'),
                1.0,
                0.0,
            ),
        };
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private function line(array $from, array $to, Style $style, ?float $width = null): void
    {
        $this->marks[] = new Mark(MarkKind::Polyline, [$from, $to], stroke: $style->color('LineColor', '#000000'), strokeWidth: $width ?? $style->number('LineThickness'));
    }

    private function text(ChartText $text, float $x, float $y, string $align): void
    {
        $this->reach = [\max($this->reach[0], $x + $text->width()), \max($this->reach[1], $y + $text->height() - ($text->size * ChartText::DESCENT))];
        \array_push($this->marks, ...$text->marks($x, $y, $align));
    }
}
