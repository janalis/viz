<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;

/**
 * A chart's series drawn in its plot as PlantUML's renderers draw them: the bars first — one
 * series alone, or several side by side or stacked — then each line and scatter in turn, then
 * the areas, each stacked on those before it.
 */
final class ChartPlot
{
    /** @var list<Mark> */
    private array $marks = [];

    public function __construct(
        private readonly ChartDrawing $chart,
        private readonly float $left,
        private readonly float $top,
        private readonly float $width,
        private readonly float $height,
    ) {}

    /** @return list<Mark> */
    public function marks(): array
    {
        $series = $this->chart->chart->series;
        if ([] === $series || (0 === $this->count() && null === $series[0]->xs)) {
            return [];
        }
        $this->bars();
        $areas = [];
        foreach ($series as $one) {
            $axis = $one->secondary && null !== $this->chart->y2Axis ? $this->chart->y2Axis : $this->chart->yAxis;
            match ($one->kind) {
                ChartKind::Bar => null,
                ChartKind::Area => $areas[] = $one,
                ChartKind::Line => $this->line($one, $axis, $one->color ?? $this->chart->style($one)->color('LineColor', '#000000')),
                ChartKind::Scatter => $this->scatter($one, $axis),
            };
        }
        $this->areas($areas);

        return $this->marks;
    }

    private function count(): int
    {
        return \count($this->chart->labels);
    }

    private function bars(): void
    {
        $bars = \array_values(\array_filter($this->chart->chart->series, static fn (ChartSeries $one): bool => ChartKind::Bar === $one->kind));
        if ([] === $bars || 0 === $this->count()) {
            return;
        }
        $colors = \array_map(fn (ChartSeries $one): string => $one->color ?? $this->chart->style($one)->color('BackGroundColor', '#000000'), $bars);
        $base = $this->chart->style(null, ChartKind::Bar);
        match (true) {
            1 === \count($bars) && $this->chart->chart->horizontal => $this->across($bars[0], $colors[0]),
            1 === \count($bars) => $this->stacked($bars, $colors, $this->chart->style($bars[0])),
            $this->chart->chart->stacked => $this->stacked($bars, $colors, $base),
            default => $this->grouped($bars, $colors, $base),
        };
    }

    /** How far down the plot a value stands on the primary axis. */
    private function y(float $value, ?ChartAxis $axis = null): float
    {
        return $this->height - (($axis ?? $this->chart->yAxis)->ratio($value) * $this->height);
    }

    private static function share(Style $style): float
    {
        $share = $style->number('BarWidth');

        return $share > 0 && $share <= 1.0 ? $share : 0.6;
    }

    /**
     * Bars standing on zero, one series' alone or several stacked, positives up from zero and
     * negatives down: as the single series' `drawVertical` and `drawStacked`, but that a lone
     * bar's zero stays in the plot and a lone bar of no height is not drawn.
     *
     * @param list<ChartSeries> $bars
     * @param list<string> $colors
     */
    private function stacked(array $bars, array $colors, Style $style): void
    {
        $alone = 1 === \count($bars);
        $column = $this->width / $this->count();
        $width = $column * self::share($style);
        $zero = $alone ? \max(0.0, \min($this->height, $this->y(0.0))) : $this->y(0.0);
        for ($category = 0; $category < $this->count(); ++$category) {
            [$up, $down] = [$zero, $zero];
            foreach ($bars as $index => $one) {
                $value = $one->values[$category] ?? null;
                if (null === $value) {
                    continue;
                }
                $x = ($category * $column) + (($column - $width) / 2);
                $height = $value < 0 ? $this->y($value) - $zero : $zero - $this->y($value);
                $y = $value < 0 ? $down : $up - $height;
                $down += $value < 0 ? $height : 0.0;
                $up -= $value < 0 ? 0.0 : $height;
                if (!$alone || $height > 0) {
                    $this->bar($x, $y, $width, $height, $colors[$index], $style);
                }
                if ($one->labels) {
                    $this->label($value, $x + ($width / 2), $value < 0 ? $y + $height + 5 : $y - 5);
                }
            }
        }
    }

    /**
     * As `drawGrouped`: each category's bars side by side across four fifths of it.
     *
     * @param list<ChartSeries> $bars
     * @param list<string> $colors
     */
    private function grouped(array $bars, array $colors, Style $style): void
    {
        $column = $this->width / $this->count();
        $width = ($column * 0.8) / \count($bars);
        $zero = \max(0.0, \min($this->height, $this->y(0.0)));
        for ($category = 0; $category < $this->count(); ++$category) {
            foreach ($bars as $index => $one) {
                $value = $one->values[$category] ?? null;
                if (null === $value) {
                    continue;
                }
                $x = ($category * $column) + ($column * 0.1) + ($index * $width);
                $y = $value < 0 ? $zero : $this->y($value);
                $height = $value < 0 ? $this->y($value) - $zero : $zero - $y;
                $this->bar($x, $y, $width, $height, $colors[$index], $style);
                if ($one->labels) {
                    $this->label($value, $x + ($width / 2), $value < 0 ? $y + $height + 5 : $y - 5);
                }
            }
        }
    }

    /** As `drawHorizontal`: a lone series' bars from the left, each as long as its value from the axis' minimum. */
    private function across(ChartSeries $bars, string $color): void
    {
        $style = $this->chart->style($bars);
        $row = $this->height / $this->count();
        $height = $row * self::share($style);
        foreach (\array_slice($bars->values, offset: 0, length: $this->count()) as $category => $value) {
            $y = ($category * $row) + (($row - $height) / 2);
            $length = \abs($this->chart->yAxis->ratio($value) * $this->width);
            $this->bar(0.0, $y, $length, $height, $color, $style);
            if ($bars->labels) {
                $text = new ChartText(ChartText::value($value), 10.0, FontFace::Bold);
                \array_push($this->marks, ...$text->marks($this->left + $length + 5, $this->top + $y + ($height / 2) - ($text->height() / 2)));
            }
        }
    }

    private function bar(float $x, float $y, float $width, float $height, string $color, Style $style): void
    {
        $this->marks[] = ClassBox::rectangle($this->left + $x, $this->top + $y, $width, $height, $style->color('LineColor', '#000000'), $color, $style->number('LineThickness'), 0.0);
    }

    /** A value written in bold above a point, as the renderers' `drawLabel`. */
    private function label(float $value, float $x, float $y): void
    {
        $text = new ChartText(ChartText::value($value), 10.0, FontFace::Bold);
        \array_push($this->marks, ...$text->marks($this->left + $x - ($text->width() / 2), $this->top + $y - $text->height() - 2, 'center'));
    }

    /** @return list<array{float, float}> each value's point in the plot, at its category's middle or its own place across */
    private function points(ChartSeries $series, ChartAxis $axis): array
    {
        $points = [];
        foreach ($series->values as $index => $value) {
            $x = null === $series->xs ? ($index + 0.5) * ($this->width / \max(1, $this->count())) : $this->chart->xAxis->pixel($series->xs[$index], 0.0, $this->width);
            if (null === $series->xs && $index >= $this->count()) {
                break;
            }
            $points[] = [$x, $this->y($value, $axis)];
        }

        return $points;
    }

    /** As `LineRenderer`: a line 2 wide from each point to the next. */
    private function line(ChartSeries $series, ChartAxis $axis, string $color): void
    {
        $points = $this->points($series, $axis);
        for ($index = 1; $index < \count($points); ++$index) {
            $this->segment($points[$index - 1], $points[$index], $color);
        }
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private function segment(array $from, array $to, string $color): void
    {
        $this->marks[] = new Mark(MarkKind::Polyline, [[$this->left + $from[0], $this->top + $from[1]], [$this->left + $to[0], $this->top + $to[1]]], stroke: $color, strokeWidth: 2.0);
    }

    /** As `ScatterRenderer`: a marker at each point, in the style's marker colour, size and shape. */
    private function scatter(ChartSeries $series, ChartAxis $axis): void
    {
        $style = $this->chart->style($series);
        $size = $style->number('MarkerSize');
        $size = $size > 0 ? $size : 8.0;
        foreach ($this->points($series, $axis) as $index => [$x, $y]) {
            $this->marks[] = self::marker($this->chart->shape($series), $style->color('MarkerColor', '#000000'), $this->left + $x, $this->top + $y, $size);
            if ($series->labels) {
                $this->label($series->values[$index], $x, $y - ($size / 2) - 8);
            }
        }
    }

    /** A marker centred on a point: a disc, a square or a triangle pointing up. */
    public static function marker(string $shape, string $color, float $x, float $y, float $size): Mark
    {
        $half = $size / 2;

        return match ($shape) {
            'square' => ClassBox::rectangle($x - $half, $y - $half, $size, $size, $color, $color, 1.0, 0.0),
            'triangle' => new Mark(MarkKind::Polygon, [[$x, $y - $half], [$x - $half, $y + $half], [$x + $half, $y + $half]], stroke: $color, fill: $color, strokeWidth: 1.0),
            default => DescriptionBox::ellipse($x - $half, $y - $half, $size, $size, $color, $color, 1.0),
        };
    }

    /**
     * As `AreaRenderer`, each area on the sum of those before it: filled down to that sum or the
     * axis' minimum, its top drawn 2 wide.
     *
     * @param list<ChartSeries> $areas
     */
    private function areas(array $areas): void
    {
        if ([] === $areas || 0 === $this->count()) {
            return;
        }
        $axis = $areas[0]->secondary && null !== $this->chart->y2Axis ? $this->chart->y2Axis : $this->chart->yAxis;
        $column = $this->width / $this->count();
        $base = null;
        foreach ($areas as $area) {
            $color = $area->color ?? $this->chart->style($area)->color('BackGroundColor', '#000000');
            $values = \array_slice($area->values, offset: 0, length: $this->count());
            $tops = [];
            $bottoms = [];
            foreach ($values as $index => $value) {
                $x = $this->left + (($index + 0.5) * $column);
                $tops[] = [$x, $this->top + $this->y(($base[$index] ?? 0.0) + $value, $axis)];
                $bottoms[] = [$x, $this->top + $this->y($base[$index] ?? $axis->min, $axis)];
            }
            $this->marks[] = new Mark(MarkKind::Polygon, [...$tops, ...\array_reverse($bottoms)], stroke: $color, fill: $color, strokeWidth: 1.0);
            for ($index = 1; $index < \count($tops); ++$index) {
                $this->marks[] = new Mark(MarkKind::Polyline, [$tops[$index - 1], $tops[$index]], stroke: $color, strokeWidth: 2.0);
            }
            if ($area->labels) {
                foreach ($tops as $index => [$x, $y]) {
                    $this->label($values[$index], $x - $this->left, $y - $this->top - 8);
                }
            }
            $base = self::sums($base, $area->values);
        }
    }

    /**
     * @param ?list<float> $base
     * @param list<float> $values
     * @return list<float>
     */
    private static function sums(?array $base, array $values): array
    {
        if (null === $base) {
            return $values;
        }
        foreach ($values as $index => $value) {
            $base[$index] = ($base[$index] ?? 0.0) + $value;
        }

        return \array_values($base);
    }
}
