<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/**
 * A row of the chart drawn as PlantUML's `TaskDraw`s draw it: a task a bar from its start to
 * its end, cut where it pauses or its days are closed, its completion filled from the left,
 * its name in it or after it; a milestone a diamond, its name after it; a group a bracket over
 * its tasks, its name over it; a separator a line across, its comment on the left. Each has
 * its height and its place down the chart, and where arrows meet it.
 */
final class GanttBar
{
    public float $y = 0.0;
    private readonly Style $style;
    private readonly GanttText $title;

    /** @param list<GanttConstraint> $constraints the arrows that touch it */
    public function __construct(
        public readonly GanttEntry $entry,
        private readonly GanttScale $scale,
        private readonly StyleSheet $sheet,
        private readonly array $constraints,
        private readonly int $minDay,
        private readonly int $maxDay,
        string $label,
    ) {
        $this->style = $sheet->style([
            'root',
            'element',
            'ganttDiagram',
            match (true) {
                $entry instanceof GanttSeparator => 'separator',
                $entry instanceof GanttTask && $entry->diamond => 'milestone',
                default => 'task',
            },
        ], $entry->stereotypes);
        $this->title =
            $entry instanceof GanttSeparator && null === $entry->comment
                ? new GanttText([], 11.0)
                : GanttText::styled(\explode("\n", \str_replace('\n', replace: "\n", subject: $label)), $this->style);
    }

    /** The height of its row, as PlantUML's `getFullHeightTask`. */
    public function height(): float
    {
        [$top, , $bottom] = $this->style->sides('Margin');

        return $top + $this->shapeHeight() + $bottom;
    }

    /** Its row's height, and its note's below it, as PlantUML's `getHeightMax`. */
    public function heightMax(): float
    {
        $note = $this->note();

        return null === $note ? $this->height() : $this->height() + $note->size()[1];
    }

    public function shapeHeight(): float
    {
        [$top, , $bottom] = $this->style->sides('Padding');
        $entry = $this->entry;

        return match (true) {
            $entry instanceof GanttSeparator => $top + $this->title->height() + $bottom,
            $entry instanceof GanttGroup => $this->title->height() + (($this->groupEnd() - 6 - ($this->start() + 6)) > $this->title->measured() ? 2 : 0),
            $entry instanceof GanttTask && $entry->diamond => \max($this->title->height(), $this->diamond()),
            default => $top + $this->title->height() + $bottom,
        };
    }

    /** @return array{float, float, float, float} what it covers, as PlantUML's `getFingerPrint`, the way PlantUML writes each kind's */
    public function fingerPrint(): array
    {
        $start = $this->start();
        $entry = $this->entry;

        return match (true) {
            $entry instanceof GanttSeparator => [0.0, $this->y, $this->scale->x(GanttTime::start($this->maxDay + 1)), $this->y + $this->height()],
            $entry instanceof GanttGroup => [$start, $this->y, $this->groupEnd() - $start, $this->height()],
            $entry instanceof GanttTask && $entry->diamond => [$start, $this->y, $start + $this->height(), $this->y + $this->height()],
            default => [$start, $this->y, $this->scale->x($this->endMoment()) - $start, $this->height()],
        };
    }

    /** @return ?array{float, float, float, float} its note's, under it, as PlantUML's `getFingerPrintNote` */
    public function noteFingerPrint(): ?array
    {
        $note = $this->note();
        if (null === $note || ($this->entry instanceof GanttTask && $this->entry->diamond)) {
            return null;
        }
        [$width, $height] = $note->size();

        return [$this->start(), $this->y + $this->height(), $width, $height];
    }

    /** @return list<Mark> its shape and its note, its row's top at its place */
    public function marks(): array
    {
        $entry = $this->entry;

        return match (true) {
            $entry instanceof GanttSeparator => $this->separator(),
            $entry instanceof GanttGroup => $this->group(),
            $entry instanceof GanttTask && $entry->diamond => [...$this->noteMarks(), ...$this->milestone($entry)],
            $entry instanceof GanttTask => [...$this->noteMarks(), ...$this->bar($entry)],
            default => [],
        };
    }

    /** @return list<Mark> its name, as PlantUML's `drawTitle` sets it inside or after its shape */
    public function titleMarks(): array
    {
        [$top, $right, , $left] = $this->style->sides('Margin');
        [$padTop, , , $padLeft] = $this->style->sides('Padding');
        $entry = $this->entry;
        if ($entry instanceof GanttSeparator) {
            return $this->title->marks($left + $padLeft, $this->y + $top + $padTop);
        }
        if ($entry instanceof GanttGroup) {
            [$pos1, $pos2] = [$this->start() + 6, $this->groupEnd() - 6];
            $fits = ($pos2 - $pos1) > $this->title->measured();

            return $this->title->marks($fits ? $pos1 + (($pos2 - $pos1 - $this->title->width()) / 2) : $pos2 + 6, $this->y + ($fits ? 0.0 : $this->height() - $this->title->height()));
        }
        if ($entry instanceof GanttTask && $entry->diamond) {
            $x2 = $this->start() + $this->scale->width($entry->start());

            return $this->title->marks($x2 - (($x2 - $this->start() - $this->diamond()) / 2) + $padLeft, $this->y + $top + (($this->shapeHeight() - $this->title->height()) / 2));
        }
        [$pos1, $pos2] = [$this->start() + 6, $this->scale->x($this->endMoment()) - 6];

        return $this->title->marks(($pos2 - $pos1) > $this->title->measured() ? $pos1 : $this->outside($pos2), $this->y + $top + $padTop);
    }

    /** How far its name, or its note, reaches past the chart's days, as PlantUML's `getLabelOverflow`. */
    public function overflow(float $barsWidth): float
    {
        $entry = $this->entry;
        $overflow = 0.0;
        if ($entry instanceof GanttGroup) {
            [$pos1, $pos2] = [$this->start() + 6, $this->groupEnd() - 6];

            return ($pos2 - $pos1) > $this->title->measured() ? 0.0 : \max(0.0, $pos2 + 6 + $this->title->width() - $barsWidth);
        }
        if (!$entry instanceof GanttTask) {
            return 0.0;
        }
        if ($entry->diamond) {
            [, , , $padLeft] = $this->style->sides('Padding');
            $x2 = $this->start() + $this->scale->width($entry->start());
            $overflow = \max(0.0, $x2 - (($x2 - $this->start() - $this->diamond()) / 2) + $padLeft + $this->title->width() - $barsWidth);
        }
        if (!$entry->diamond) {
            [$pos1, $pos2] = [$this->start() + 6, $this->scale->x($this->endMoment()) - 6];
            if (($pos2 - $pos1) <= $this->title->measured()) {
                $overflow = \max(0.0, $this->outside($pos2) + $this->title->width() - $barsWidth);
            }
        }
        $note = $this->note();

        return null === $note ? $overflow : \max($overflow, $this->start() + $note->size()[0] - $barsWidth);
    }

    /** Where an arrow leaves (`out`) or reaches (`in`) it across, as PlantUML's `getX`. */
    public function x(string $side, string $direction): float
    {
        $entry = $this->entry;
        $start = $this->start();
        $startWidth = $this->scale->width($this->startMoment());
        if ($entry instanceof GanttTask && $entry->diamond) {
            $x = match ($side) {
                'left' => $start,
                'right' => $start + $startWidth,
                default => ($start + $start + $startWidth) / 2,
            };

            return (
                $x
                + (
                    'out' === $direction
                        ? match ($side) {
                            'left' => $this->diamond() / 2,
                            'right' => -$this->diamond() / 2,
                            default => 0.0,
                        } : 0.0
                )
            );
        }
        if ($entry instanceof GanttGroup) {
            $end = $this->scale->x($this->endMoment());

            return match ($side) {
                'left' => $start,
                'right' => $end + $this->scale->width($this->endMoment()),
                'top-left', 'bottom-left' => ($start + $start + $startWidth) / 2,
                default => ($end + $end + $this->scale->width($this->endMoment())) / 2,
            };
        }
        $end = $this->scale->x($this->endMoment());
        [, , , $marginLeft] = $this->style->sides('Margin');
        $x = match ($side) {
            'left' => $start,
            'right' => $end,
            'top-left', 'bottom-left' => ($start + 8) > $end ? ($start + $start + $startWidth) / 2 : $start + 8,
            default => $end - 8,
        };

        return (
            $x
            + (
                'out' === $direction
                    ? match ($side) {
                        'left' => $marginLeft,
                        'right' => -$marginLeft,
                        default => 0.0,
                    } : 0.0
            )
        );
    }

    /** Where an arrow leaves or reaches it down, as PlantUML's `getY`. */
    public function sideY(string $side): float
    {
        [$top] = $this->style->sides('Margin');
        $y1 = $top + $this->y;
        $y2 = $y1 + $this->shapeHeight();
        if ($this->entry instanceof GanttTask && $this->entry->diamond && null === $this->entry->displayString) {
            return $y1 + ($this->diamond() / 2);
        }

        return match ($side) {
            'top-left', 'top-right' => $y1,
            'bottom-left', 'bottom-right' => $y2,
            default => ($y1 + $y2) / 2,
        };
    }

    /** @return list<Mark> PlantUML's `TaskDrawRegular.drawShape`, with its `RectangleTask` */
    private function bar(GanttTask $task): array
    {
        if ($this->style->number('RoundCorner') > 0) {
            throw new Unsupported('The native PlantUML renderer leaves Gantt tasks with round corners to PlantUML');
        }
        [$top, $right, , $left] = $this->style->sides('Margin');
        [$line, $back] = $this->colors($task);
        $startPos = $this->start() + $left;
        $endPos = $this->scale->x($this->endMoment()) - $right;
        $y = $this->y + $top;
        $height = $this->shapeHeight();
        $undone = $this->sheet->style(['root', 'element', 'ganttDiagram', 'undone'])->color('BackGroundColor', '#ffffff');
        $pauses = [];
        foreach ($this->paused($task) as $day) {
            $x = $this->scale->x(GanttTime::start($day));
            $pauses[] = [$x, $x + $this->scale->width(GanttTime::start($day))];
        }
        $segments = $startPos < $endPos ? self::cut($startPos, $endPos, $pauses) : [[$startPos, $startPos + 1]];
        $total = \array_sum(\array_map(static fn (array $segment): float => $segment[1] - $segment[0], $segments));
        $limit = 100 === $task->completion ? $total : ($total * $task->completion) / 100;
        if (1 === \count($segments)) {
            [$x1, $x2] = $segments[0];

            return [
                ...self::backs($x1, $y, $x2 - $x1, $height, 100 === $task->completion ? -1.0 : $limit, $back, $undone),
                new Mark(MarkKind::Polygon, [[$x1, $y], [$x2, $y], [$x2, $y + $height], [$x1, $y + $height]], stroke: $line, fill: 'none', strokeWidth: 1.0),
            ];
        }

        return $this->segments($segments, $y, $height, $limit, $line, $back, $undone);
    }

    /**
     * PlantUML's `drawWithoutRound` over several stretches: each its share of the completion, the first open on the right, the last on the left, the gaps dotted.
     *
     * @param list<array{float, float}> $segments
     * @return list<Mark>
     */
    private function segments(array $segments, float $y, float $height, float $limit, string $line, string $back, string $undone): array
    {
        $marks = [];
        $current = 0.0;
        $last = \count($segments) - 1;
        foreach ($segments as $index => [$x1, $x2]) {
            $length = $x2 - $x1;
            $next = $current + $length;
            $completion = match (true) {
                $limit >= $next => -1.0,
                $current >= $limit => 0.0,
                default => $limit - $current,
            };
            $width = $length + ($index !== $last ? 1 : 0);
            if ($width > 0) {
                \array_push($marks, ...self::backs($x1, $y, $width, $height, $completion, $back, $undone));
            }
            $marks[] = match (true) {
                0 === $index => new Mark(MarkKind::Polyline, [[$x2, $y + $height], [$x1, $y + $height], [$x1, $y], [$x2, $y]], stroke: $line, strokeWidth: 1.0),
                $index === $last => new Mark(MarkKind::Polyline, [[$x1, $y], [$x2, $y], [$x2, $y + $height], [$x1, $y + $height]], stroke: $line, strokeWidth: 1.0),
                default => new Mark(MarkKind::Polyline, [[$x1, $y], [$x2, $y]], stroke: $line, strokeWidth: 1.0),
            };
            if (0 !== $index && $index !== $last) {
                $marks[] = new Mark(MarkKind::Polyline, [[$x1, $y + $height], [$x2, $y + $height]], stroke: $line, strokeWidth: 1.0);
            }
            $current = $next;
        }
        for ($index = 0; $index < $last; ++$index) {
            [$v1, $v2] = [$segments[$index][1] + 3, $segments[$index + 1][0] - 3];
            if ($v2 <= $v1) {
                continue;
            }
            foreach ([$y, $y + $height] as $at) {
                $marks[] = new Mark(MarkKind::Polyline, [[$v1, $at], [$v2, $at]], stroke: $line, dashed: true, strokeWidth: 1.0, dashes: [2.0, 3.0]);
            }
        }

        return $marks;
    }

    /**
     * PlantUML's `drawBackgroundRect`: all of it in the bar's colour (-1), or none (0), or the part done.
     *
     * @return list<Mark>
     */
    private static function backs(float $x, float $y, float $width, float $height, float $done, string $back, string $undone): array
    {
        if (-1.0 === $done || 0.0 === $done) {
            return [self::fill($x, $y, $width, $height, 0.0 === $done ? $undone : $back)];
        }

        return [self::fill($x, $y, $done, $height, $back), self::fill($x + $done, $y, $width - $done, $height, $undone)];
    }

    private static function fill(float $x, float $y, float $width, float $height, string $color): Mark
    {
        return new Mark(MarkKind::Polygon, [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]], stroke: 'none', fill: $color, strokeWidth: 0.0);
    }

    /**
     * As PlantUML's `Segment.cutSegmentIfNeed`: a stretch less the pauses within it.
     *
     * @param list<array{float, float}> $pauses
     * @return list<array{float, float}>
     */
    private static function cut(float $from, float $to, array $pauses): array
    {
        \usort($pauses, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $segments = [];
        $pending = $from;
        foreach ($pauses as [$p1, $p2]) {
            if (\abs($p1 - $pending) < 0.001) {
                $pending = $p2;
                continue;
            }
            if ($p1 < $pending) {
                continue;
            }
            if ($p1 > $to) {
                break;
            }
            if ($p1 >= $from && $p2 <= $to) {
                $segments[] = [$pending, $p1];
                $pending = $p2;
            }
        }
        if ($pending < $to) {
            $segments[] = [$pending, $to];
        }

        return $segments;
    }

    /** @return list<int> the days it pauses, and its days closed, from its start to its end */
    private function paused(GanttTask $task): array
    {
        $days = \array_flip($task->pausedDays());
        $plan = $task->plan();
        for ($moment = $this->startMoment(), $end = $this->endMoment(); $moment <= $end; $moment += GanttTime::DAY) {
            if (!$plan(GanttTime::day($moment))->zeroed()) {
                continue;
            }
            $days[GanttTime::day($moment)] = true;
        }
        $days = \array_keys($days);
        \sort($days);

        return $days;
    }

    /** @return list<Mark> PlantUML's `TaskDrawDiamond.drawU` */
    private function milestone(GanttTask $task): array
    {
        [$top] = $this->style->sides('Margin');
        $x1 = $this->start();
        $y = $this->y + $top;
        if (null !== $task->displayString) {
            return GanttText::styled([$task->displayString], $this->style)->marks($x1, $y);
        }
        $h = $this->diamond();
        $x = $x1 + (($this->scale->width($task->start()) - $h) / 2);
        [$line, $back] = $this->colors($task);

        return [new Mark(MarkKind::Polygon, [[$x + ($h / 2), $y], [$x + $h, $y + ($h / 2)], [$x + ($h / 2), $y + $h], [$x, $y + ($h / 2)]], stroke: $line, fill: $back, strokeWidth: 1.0)];
    }

    /** @return list<Mark> PlantUML's `TaskDrawGroup.drawShape`: a black bracket 10 high at its row's foot */
    private function group(): array
    {
        [, $right, , $left] = $this->style->sides('Margin');
        $start = $this->start() + $left;
        $end = $this->groupEnd() - $right;
        $y = $this->y + $this->height() - 10;
        $points = [];
        foreach ([[0.0, 0.0], [6.0, 4.0], [-6.0, 4.0], [0.0, 0.0], [0.0, 10.0], [-6.0, 6.0], [6.0, 6.0], [0.0, 10.0]] as $index => [$dx, $dy]) {
            // The first and last three points from its start, the others from its end.
            $points[] = [(\in_array($index, [0, 1, 6, 7], strict: true) ? $start : $end) + $dx, $y + $dy];
        }

        return [new Mark(MarkKind::Polygon, $points, stroke: '#000000', fill: '#000000', strokeWidth: 1.0)];
    }

    /** @return list<Mark> PlantUML's `TaskDrawSeparator.drawU` */
    private function separator(): array
    {
        [$top, $right, , $left] = $this->style->sides('Margin');
        [$padTop, , , $padLeft] = $this->style->sides('Padding');
        $start = $this->scale->x(GanttTime::start($this->minDay));
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        $y = $this->y + $top;
        $marks = [];
        $back = $this->style->color('BackGroundColor', 'none');
        $height = $padTop + $this->title->height() + $this->style->sides('Padding')[2];
        if ('none' !== $back && $height > 0) {
            $marks[] = self::fill(0.0, $y, $end - $start, $height, $back);
        }
        $line = $this->style->color('LineColor', '#181818');
        $y += $padTop + ($this->title->height() / 2);
        $width = $this->title->width();
        if (0.0 === $width) {
            return [...$marks, new Mark(MarkKind::Polyline, [[0.0, $y], [$end - $start, $y]], stroke: $line, strokeWidth: 1.0)];
        }
        if ($padLeft > 1) {
            $marks[] = new Mark(MarkKind::Polyline, [[0.0, $y], [$padLeft, $y]], stroke: $line, strokeWidth: 1.0);
        }
        $x1 = $padLeft + $left + $width + $right;

        return [...$marks, new Mark(MarkKind::Polyline, [[$x1, $y], [$end - 1, $y]], stroke: $line, strokeWidth: 1.0)];
    }

    /** @return list<Mark> its note, under its row */
    private function noteMarks(): array
    {
        return $this->note()?->marks($this->start(), $this->y + $this->height()) ?? [];
    }

    private function note(): ?NoteBox
    {
        if (!$this->entry instanceof GanttTask || null === $this->entry->note) {
            return null;
        }
        $style = $this->sheet->style(['root', 'element', 'ganttDiagram', 'note']);

        return new NoteBox(PlantUmlText::styled($this->entry->note, $style), $style);
    }

    /** @return array{string, string} its line and fill colours: its own, blended by its completion when two, or its style's */
    private function colors(GanttTask $task): array
    {
        $colors = $task->colors;
        if ([] === $colors || null === $colors[0][0]) {
            return [$this->style->color('LineColor', '#181818'), $this->style->color('BackGroundColor', '#e2e2f0')];
        }
        [$center, $border] = $colors[0];
        if (2 === \count($colors)) {
            $center = GanttColors::blend($colors[0][0], $colors[1][0], $task->completion);
            $border = GanttColors::blend($colors[0][1], $colors[1][1], $task->completion);
        }

        return [$border ?? $center ?? '#000000', $center ?? '#ffffff'];
    }

    /** As PlantUML's `getDiamondHeight`: its font size, down to an even number. */
    private function diamond(): float
    {
        $size = (int) $this->style->fontSize();

        return (float) ($size - ($size % 2));
    }

    private function outside(float $pos2): float
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->rightArrow($this->entry)) {
                return $pos2 + 18;
            }
        }

        return $pos2 + 8;
    }

    private function start(): float
    {
        return $this->scale->x($this->startMoment());
    }

    private function startMoment(): int
    {
        $entry = $this->entry;

        return $entry instanceof GanttMoment ? $entry->start() : GanttTime::start($this->minDay);
    }

    private function endMoment(): int
    {
        $entry = $this->entry;

        return $entry instanceof GanttMoment ? $entry->end() : GanttTime::start($this->maxDay + 1);
    }

    /** A group's end, past its last day. */
    private function groupEnd(): float
    {
        return $this->scale->x($this->endMoment()) + $this->scale->width($this->endMoment());
    }
}
