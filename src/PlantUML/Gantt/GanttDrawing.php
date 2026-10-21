<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A Gantt chart laid out and drawn as PlantUML's `GanttDiagramMainBlock` draws it: the task
 * table on the left; the calendar over the days, and again under them; each row under the
 * last, but on another's row, and below any note it would cross; the arrows, the bars and
 * their names; each resource's load per day under the rows. 20 wider than all that.
 */
final class GanttDrawing
{
    /** @var list<GanttBar> */
    private array $bars = [];
    /** @var array<int, GanttBar> by entry */
    private array $byEntry = [];
    private GanttHeader $header;
    private float $total = 0.0;
    /** @var list<array{GanttResource, float}> each resource drawn, and where */
    private array $resources = [];

    private function __construct(
        private readonly PlantUmlGantt $diagram,
        private readonly int $minDay,
        private readonly int $maxDay,
    ) {}

    public static function draw(PlantUmlGantt $diagram): Drawing
    {
        $chart = $diagram->chart;
        $maxDay = $chart->maxDay();
        if (($maxDay - $chart->minDay) > (365 * 20)) {
            throw new PlantUmlRefusal('Gantt diagrams cannot last more than 20 years');
        }
        $drawing = new self($diagram, $chart->minDay, $maxDay);
        $drawing->layout();

        return $drawing->paint();
    }

    private function layout(): void
    {
        $chart = $this->diagram->chart;
        $sheet = $this->diagram->document->styles;
        $cell = $sheet->style(['root', 'element', 'ganttDiagram', 'timeline', 'day'])->fontSize() * 1.6;
        $factor =
            match ($chart->scale) {
                'weekly' => 1 / 4,
                'monthly' => 1 / 15,
                'quarterly' => 1 / 40,
                'yearly' => 1 / 60,
                default => 1.0,
            } * $chart->zoom;
        $scale = match (true) {
            $chart->relative() => new GanttScale($cell * $factor, 0, 'weekly' === $chart->scale ? 'week' : 'day'),
            'daily' === $chart->scale && $chart->hideClosed => new GanttScale($cell * $factor, GanttTime::start($this->minDay), 'day', $chart->calendar),
            'daily' === $chart->scale => new GanttScale($cell * $factor, GanttTime::start($this->minDay), 'day'),
            default => new GanttScale($cell * $factor, GanttTime::start($this->minDay), 'sunday'),
        };
        $this->header = new GanttHeader($chart, $scale, $this->minDay, $this->maxDay, $sheet);
        $next = $this->header->fullHeaderHeight();
        /** @var list<GanttBar> $notes */
        $notes = [];
        foreach ($chart->entries as $entry) {
            $label = match (true) {
                $entry instanceof GanttSeparator => (string) $entry->comment,
                $entry instanceof GanttTask && !$chart->hideResourceNames => $entry->prettyDisplay(),
                default => $entry->display,
            };
            $bar = new GanttBar($entry, $scale, $sheet, \array_values(\array_filter($chart->constraints, static fn (GanttConstraint $constraint): bool => $constraint->isOn(
                $entry,
            ))), $this->minDay, $this->maxDay, $label);
            $row = null === $entry->row ? null : $this->byEntry[\spl_object_id($entry->row)] ?? null;
            $bar->y = null === $row ? $next : $row->y;
            if (null === $row) {
                $this->below($bar, $notes);
                $next = $bar->y + $bar->height();
            }
            if (null !== $bar->noteFingerPrint()) {
                $notes[] = $bar;
            }
            $this->bars[] = $bar;
            $this->byEntry[\spl_object_id($entry)] = $bar;
        }
        $bottom = \array_reduce($this->bars, static fn (float $max, GanttBar $bar): float => \max($max, $bar->y + $bar->heightMax()), initial: 0.0);
        if (0.0 === $bottom) {
            $bottom = $this->header->fullHeaderHeight();
        }
        if (!$chart->hideResourceFootbox && [] !== $this->bars) {
            foreach ($chart->resources as $resource) {
                $this->resources[] = [$resource, $bottom];
                $bottom += 32;
            }
        }
        $this->total = $bottom;
    }

    /**
     * As PlantUML's `resolveNoteOverlaps`: a row put below each note before it that it would cross.
     *
     * @param list<GanttBar> $notes
     */
    private function below(GanttBar $bar, array $notes): void
    {
        $print = $bar->fingerPrint();
        foreach ($notes as $note) {
            $other = $note->noteFingerPrint();
            if (null === $other || !self::overlap($other, $print)) {
                continue;
            }
            $bar->y = \max($bar->y, $note->y + $note->heightMax());
        }
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function overlap(array $a, array $b): bool
    {
        if ($a[0] >= ($b[0] + $b[2]) || $b[0] >= ($a[0] + $a[2])) {
            return false;
        }

        return !($a[1] >= ($b[1] + $b[3]) || $b[1] >= ($a[1] + $a[3])) && ($a[1] + $a[3] - $b[1]) > 0;
    }

    private function paint(): Drawing
    {
        $chart = $this->diagram->chart;
        $sheet = $this->diagram->document->styles;
        $timeline = $sheet->style(['root', 'element', 'ganttDiagram', 'timeline']);
        $table = new GanttTable($chart, $this->bars, $timeline, $this->header->fullHeaderHeight());
        $scale = $this->header->scale;
        $timelineWidth = $scale->x(GanttTime::start($this->maxDay + 1)) - $scale->x(GanttTime::start($this->minDay));
        $overflow = \array_reduce($this->bars, static fn (float $max, GanttBar $bar): float => \max($max, $bar->overflow($timelineWidth)), initial: 0.0);
        $marks = [];
        $back = $timeline->color('BackGroundColor', 'none');
        if ('none' !== $back) {
            $marks[] = self::box(0.0, 0.0, $timelineWidth, $this->header->headerHeight(), $back);
            if ($chart->footbox) {
                $marks[] = self::box(0.0, $this->total, $timelineWidth, $this->header->footerHeight(), $back);
            }
        }
        \array_push($marks, ...$this->header->header($this->total));
        foreach ($chart->constraints as $constraint) {
            \array_push($marks, ...$this->arrow($constraint));
        }
        foreach ($this->bars as $bar) {
            \array_push($marks, ...$bar->marks());
        }
        foreach ($this->bars as $bar) {
            \array_push($marks, ...$bar->titleMarks());
        }
        foreach ($this->resources as [$resource, $y]) {
            \array_push($marks, ...$this->resource($resource, $y));
        }
        if ($chart->footbox) {
            \array_push($marks, ...$this->header->footer($this->total));
        }
        $width = $table->width() + $timelineWidth + $overflow + 20;
        $height = $this->total + ($chart->footbox ? $this->header->footerHeight() : 0.0);
        $placed = [...$table->marks($this->total), ...\array_map(static fn (Mark $mark): Mark => Units::moved($mark, $table->width(), 0.0), $marks)];

        return PlantUmlFrame::bare($this->diagram->document, $placed, \floor($width) + 1, \floor($height) + 1);
    }

    /** @return list<Mark> PlantUML's `GanttArrow`: from one row to another, its head 4 long */
    private function arrow(GanttConstraint $constraint): array
    {
        $source = $constraint->source;
        $dest = $constraint->dest;
        $start = $source->moment instanceof GanttEntry ? $this->byEntry[\spl_object_id($source->moment)] ?? null : null;
        $end = $dest->moment instanceof GanttEntry ? $this->byEntry[\spl_object_id($dest->moment)] ?? null : null;
        if (null === $start || null === $end) {
            return [];
        }
        [$atStart, $atEnd] = match (true) {
            $source->end && !$dest->end => [$source->sameRowAs($dest) ? 'right' : 'bottom-right', 'left'],
            $source->end => ['right', 'right'],
            !$dest->end => ['left', 'left'],
            default => [$source->sameRowAs($dest) ? 'left' : 'bottom-left', 'right'],
        };
        $x1 = $start->x($atStart, 'out');
        $y1 = $start->sideY($atStart);
        $x2 = $end->x($atEnd, 'in');
        $y2 = $end->sideY($atEnd);
        if (\str_starts_with($atStart, 'bottom') && $y2 < $y1) {
            $y1 = $start->sideY('top'.\substr($atStart, offset: 6));
        }
        $points = match (true) {
            \str_starts_with($atStart, 'bottom') && 'left' === $atEnd && $x2 > ($x1 + 6) => [[$x1, $y1], [$x1, $y2], [$x2, $y2]],
            \str_starts_with($atStart, 'bottom') && 'left' === $atEnd => self::around($start, $end, $x2, $y2),
            'right' === $atStart && 'right' === $atEnd => [[$x1, $y1], [\max($x1, $x2) + 8, $y1], [\max($x1, $x2) + 8, $y2], [$x2, $y2]],
            'left' === $atStart && 'left' === $atEnd => [[$x1, $y1], [\min($x1, $x2) - 8, $y1], [\min($x1, $x2) - 8, $y2], [$x2, $y2]],
            default => [[$x1, $y1], [$x1, $y2], [$x2, $y2]],
        };
        $style = $this->diagram->document->styles->style(['root', 'element', 'ganttDiagram', 'arrow']);
        $color = $constraint->color ?? $style->color('LineColor', '#181818');
        $last = \count($points) - 1;
        [$tipX, $tipY] = $points[$last];
        $points[$last] = [$tipX + ('left' === $atEnd ? -3 : 3), $tipY];
        $dashes = match ($constraint->style) {
            'dashed' => [7.0, 7.0],
            'dotted' => [1.0, 3.0],
            default => $style->dashes(),
        };
        $head = 'left' === $atEnd ? -4.0 : 4.0;

        return [
            new Mark(MarkKind::Polyline, $points, stroke: $color, dashed: null !== $dashes, strokeWidth: 'bold' === $constraint->style ? 2.0 : $style->number('LineThickness', 1.5), dashes: $dashes),
            new Mark(MarkKind::Polygon, [[$tipX + $head, $tipY - 4], [$tipX, $tipY], [$tipX + $head, $tipY + 4]], stroke: $color, fill: $color, strokeWidth: 1.0),
        ];
    }

    /** @return list<array{float, float}> an arrow round a row it would run back over: out right, down to the other's top, back and in */
    private static function around(GanttBar $start, GanttBar $end, float $x2, float $y2): array
    {
        $x1 = $start->x('right', 'out');
        $y1 = $start->sideY('right');

        return [[$x1, $y1], [$x1 + 6, $y1], [$x1 + 6, $end->y], [$x2 - 8, $end->y], [$x2 - 8, $y2], [$x2, $y2]];
    }

    /** @return list<Mark> PlantUML's `ResourceDrawNumbers`: its name, a line, and its average load over each stretch, red past 100 */
    private function resource(GanttResource $resource, float $y): array
    {
        $chart = $this->diagram->chart;
        $scale = $this->header->scale;
        $title = new GanttText([$resource->name], 13.0, serif: true);
        $min = GanttTime::start($this->minDay);
        $last = GanttTime::start($this->maxDay + 1) - 1;
        $marks = [
            ...$title->marks(0.0, $y),
            new Mark(MarkKind::Polyline, [[0.0, $y + $title->height()], [$scale->x($last) - $scale->x($min), $y + $title->height()]], stroke: '#000000', strokeWidth: 1.0),
        ];
        [$from, $total, $active, $peak] = [-1.0, 0, 0, 0];
        for ($moment = $min; $moment <= $last; $moment += GanttTime::DAY) {
            $load = 0;
            foreach ($chart->entries as $entry) {
                if (!$entry instanceof GanttTask) {
                    continue;
                }
                $load += $entry->loadFor($resource, GanttTime::day($moment));
            }
            $peak = \max($peak, $load);
            $total += $load;
            $active += $load > 0 ? 1 : 0;
            if (-1.0 === $from) {
                $from = $scale->x($moment);
            }
            if (!$scale->breaking($moment)) {
                continue;
            }
            if ($total > 0) {
                $value = new GanttText([(string) (int) \round($total / $active)], 9.0, color: $peak > 100 ? '#ff0000' : '#000000', serif: true);
                $middle = ($from + $scale->x($moment) + $scale->width($moment)) / 2;
                \array_push($marks, ...$value->marks($middle - ($value->width() / 2), $y + 16));
            }
            [$from, $total, $active, $peak] = [-1.0, 0, 0, 0];
        }

        return $marks;
    }

    private static function box(float $x, float $y, float $width, float $height, string $color): Mark
    {
        return new Mark(MarkKind::Polygon, [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]], stroke: 'none', fill: $color, strokeWidth: 0.0);
    }
}
