<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * PlantUML's `GanttTaskTable`: left of the chart, a column for each of the task's name, start,
 * end and duration shown — start, end and duration by default — its header in the calendar's
 * height, a row level with each task; days numbered from 1 when the chart has no first day.
 */
final readonly class GanttTable
{
    private const float PADDING = 5.0;

    /** @var list<float> */
    private array $edges;

    /** @param list<GanttBar> $bars */
    public function __construct(
        private GanttChart $chart,
        private array $bars,
        private Style $style,
        private float $headerHeight,
    ) {
        $edges = [0.0];
        foreach ($chart->columns as $column) {
            $width = $this->text(self::header($column))->width();
            foreach ($this->rows() as $bar) {
                $width = \max($width, $this->text($this->value($column, $bar->entry))->width());
            }
            $edges[] = $edges[\count($edges) - 1] + $width + (2 * self::PADDING);
        }
        $this->edges = $edges;
    }

    public function width(): float
    {
        return $this->edges[\count($this->edges) - 1];
    }

    /** @return list<Mark> */
    public function marks(float $total): array
    {
        $line = $this->style->color('LineColor', '#c0c0c0');
        $width = $this->width();
        $marks = [];
        foreach ([0.0, $this->headerHeight, $total] as $y) {
            $marks[] = new Mark(MarkKind::Polyline, [[0.0, $y], [$width, $y]], stroke: $line, strokeWidth: 1.0);
        }
        foreach ($this->edges as $x) {
            $marks[] = new Mark(MarkKind::Polyline, [[$x, 0.0], [$x, $total]], stroke: $line, strokeWidth: 1.0);
        }
        foreach ($this->chart->columns as $index => $column) {
            \array_push($marks, ...$this->cell($this->edges[$index], $this->headerHeight / 2, self::header($column)));
        }
        foreach ($this->rows() as $bar) {
            foreach ($this->chart->columns as $index => $column) {
                \array_push($marks, ...$this->cell($this->edges[$index], $bar->y + ($bar->height() / 2), $this->value($column, $bar->entry)));
            }
        }

        return $marks;
    }

    /** @return list<GanttBar> the tasks', milestones included */
    private function rows(): array
    {
        return \array_values(\array_filter($this->bars, static fn (GanttBar $bar): bool => $bar->entry instanceof GanttTask));
    }

    /** @return list<Mark> */
    private function cell(float $left, float $middle, string $value): array
    {
        $text = $this->text($value);

        return $text->marks($left + self::PADDING, $middle - ($text->height() / 2));
    }

    private function text(string $value): GanttText
    {
        return GanttText::styled([$value], $this->style);
    }

    private static function header(string $column): string
    {
        return ['TASK' => 'Task', 'START' => 'Start', 'END' => 'End', 'DURATION' => 'Duration'][$column] ?? $column;
    }

    private function value(string $column, GanttEntry $entry): string
    {
        if (!$entry instanceof GanttTask) {
            return '';
        }

        return match ($column) {
            'TASK' => $entry->display,
            'START' => $this->withTime($entry->start()),
            'END' => GanttTime::midnight($entry->end()) ? $this->day($entry->end() - 1) : $this->withTime($entry->end()),
            default => self::duration($entry->end() - $entry->start()),
        };
    }

    /** A day, as PlantUML's `formatDay`: `Day 3` without a first day, else `Mar 1`. */
    private function day(int $seconds): string
    {
        $day = GanttTime::day($seconds);
        if ($this->chart->relative()) {
            return 'Day '.($day - $this->chart->minDay + 1);
        }
        [, $month, $date] = GanttTime::date($day);

        return GanttTime::monthShort($month).' '.$date;
    }

    /** A moment, as PlantUML's `formatDayWithTime`: its day, and its time when not midnight. */
    private function withTime(int $seconds): string
    {
        $time = (($seconds % GanttTime::DAY) + GanttTime::DAY) % GanttTime::DAY;
        if (0 === $time) {
            return $this->day($seconds);
        }
        [$hour, $minute, $second] = [\intdiv($time, num2: 3600), \intdiv($time, num2: 60) % 60, $time % 60];

        return $this->day($seconds).' '.(0 === $second ? \sprintf('%02d:%02d', $hour, $minute) : \sprintf('%02d:%02d:%02d', $hour, $minute, $second));
    }

    /** As PlantUML's `durationHumanReadable`: days, hours, minutes and seconds, those there are. */
    private static function duration(int $seconds): string
    {
        if (0 === $seconds) {
            return 'none';
        }
        $parts = [];
        foreach ([['day', 86_400], ['hour', 3600], ['minute', 60], ['second', 1]] as [$unit, $length]) {
            $count = \intdiv($seconds, $length);
            $seconds %= $length;
            if ($count > 0) {
                $parts[] = $count.' '.$unit.($count > 1 ? 's' : '');
            }
        }

        return \implode(', ', $parts);
    }
}
