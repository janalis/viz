<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Text\FontFace;

/**
 * PlantUML's `TimeHeader`s: the calendar above the chart and below it — day numbers from 1
 * when no first day is given; else, daily, the months, weekdays and days of the month; weekly,
 * the months and week numbers; monthly, quarterly and yearly, the years and their months or
 * quarters — with the days closed, coloured or named shaded down the chart, and separators.
 */
final class GanttHeader
{
    private readonly float $daySize;
    private readonly float $monthSize;
    private readonly float $yearSize;
    private readonly string $line;
    private readonly string $font;
    private ?int $delta = null;

    public function __construct(
        private readonly GanttChart $chart,
        public readonly GanttScale $scale,
        private readonly int $minDay,
        private readonly int $maxDay,
        private readonly StyleSheet $sheet,
    ) {
        $this->daySize = $this->style('timeline', 'day')->fontSize();
        $this->monthSize = $this->style('timeline', 'month')->fontSize();
        $this->yearSize = $this->style('timeline', 'year')->fontSize();
        $this->line = $this->style('timeline')->color('LineColor', '#c0c0c0');
        $this->font = $this->style('timeline')->color('FontColor', '#000000');
    }

    public function kind(): string
    {
        return $this->chart->relative() ? 'simple' : $this->chart->scale;
    }

    public function headerHeight(): float
    {
        return match ($this->kind()) {
            'simple' => $this->daySize + 6,
            'daily' => $this->monthSize + 2 + $this->daySize + 2 + $this->daySize + 3,
            'weekly' => $this->monthSize + 4 + $this->daySize + 1,
            'monthly' => $this->yearSize + 2 + $this->monthSize + 2,
            'quarterly' => $this->yearSize + $this->monthSize + 5,
            default => $this->yearSize + 3,
        };
    }

    public function fullHeaderHeight(): float
    {
        $named = [] !== $this->chart->names && \in_array($this->kind(), ['daily', 'weekly', 'monthly'], strict: true) ? $this->daySize + 6 : 0.0;

        return $this->headerHeight() + $named;
    }

    public function footerHeight(): float
    {
        return match ($this->kind()) {
            'simple' => $this->daySize + 6,
            'daily' => $this->daySize + $this->daySize + $this->monthSize + 8,
            'weekly' => $this->monthSize + 4,
            'monthly', 'quarterly' => $this->yearSize + $this->monthSize + 5,
            default => $this->yearSize + 3,
        };
    }

    /** @return list<Mark> */
    public function header(float $total): array
    {
        $marks = $this->background($total);
        $full = $this->fullHeaderHeight();

        return [
            ...$marks,
            ...match ($this->kind()) {
                'simple' => [...$this->smallLines($total), ...$this->separators($total), ...$this->counter(0.0)],
                'daily' => [
                    ...$this->months(0.0, $this->hidden(...)),
                    ...$this->weekdays($this->monthSize + 2),
                    ...$this->daysOfMonth($this->monthSize + 2 + $this->daySize + 2),
                    ...$this->dailySeparators($total),
                    ...$this->namedDays(),
                    $this->hline($full),
                    $this->hline($total),
                ],
                'weekly' => [
                    ...$this->weekNumbers(),
                    ...$this->weeklySeparators($total),
                    ...$this->weeklyMonths(0.0),
                    ...$this->namedDays(),
                    $this->hline(0.0),
                    $this->hline($this->monthSize + 4),
                    $this->hline($full),
                ],
                'monthly' => [
                    ...$this->years(0.0, 'month'),
                    ...$this->spans($this->yearSize + 2, 'month'),
                    ...$this->separators($total),
                    ...$this->namedDays(),
                    $this->hline(0.0),
                    $this->hline($this->yearSize + 2),
                    $this->hline($this->yearSize + 2 + $this->monthSize + 2),
                ],
                'quarterly' => [
                    ...$this->years(0.0, 'month'),
                    ...$this->spans($this->yearSize + 2, 'quarter'),
                    ...$this->separators($total),
                    $this->hline(0.0),
                    $this->hline($this->yearSize + 2),
                    $this->hline($this->yearSize + 2 + $this->monthSize + 2),
                ],
                default => [...$this->years(0.0, 'year'), ...$this->separators($total), $this->hline(0.0), $this->hline($this->headerHeight())],
            },
        ];
    }

    /** @return list<Mark> the calendar again under the chart, from a height */
    public function footer(float $y): array
    {
        return match ($this->kind()) {
            'simple' => $this->counter($y + 3),
            'daily' => [
                ...$this->weekdays($y),
                ...$this->daysOfMonth($y + $this->daySize + 2 + 2),
                ...$this->months($y + (2 * ($this->daySize + 2)) + 3, $this->hidden(...)),
            ],
            'weekly' => [$this->hline($y), ...$this->weeklyMonths($y), $this->hline($y + $this->footerHeight())],
            'monthly', 'quarterly' => [
                ...$this->spans($y, 'monthly' === $this->kind() ? 'month' : 'quarter'),
                ...$this->years($y + $this->monthSize + 2, 'month'),
                $this->hline($y),
                $this->hline($y + $this->monthSize + 2),
                $this->hline($y + $this->yearSize + 2 + $this->monthSize + 2),
            ],
            default => [...$this->years($y, 'year'), $this->hline($y), $this->hline($y + $this->footerHeight())],
        };
    }

    /** @return list<Mark> the days coloured, today, a weekday's colour, or closed, shaded down the chart, as PlantUML's `drawColorsBackground` */
    private function background(float $total): array
    {
        $full = $this->fullHeaderHeight();
        $height = $total - $full;
        $marks = [];
        $pending = null;
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            $start = GanttTime::start($day);
            [$x1, $x2] = [$this->scale->x($start), $this->scale->x($start) + $this->scale->width($start)];
            $back = ($day === $this->chart->todayDay() ? $this->chart->todayColor : null) ?? $this->chart->colors[$day] ?? null;
            $back = $this->chart->weekdayColors[GanttTime::weekday($day)] ?? $back;
            if (null === $back && 'simple' !== $this->kind() && $this->chart->calendar->rate($day)->zeroed()) {
                $back = $this->style('closed')->color('BackGroundColor', '#f1e5e5');
            }
            if (null === $back) {
                $marks = [...$marks, ...self::shade($pending, $full, $height)];
                $pending = null;
                continue;
            }
            if (null !== $pending && $pending[0] !== $back) {
                $marks = [...$marks, ...self::shade($pending, $full, $height)];
                $pending = null;
            }
            $pending = null === $pending ? [$back, $x1, $x2] : [$back, $pending[1], $x2];
        }

        return [...$marks, ...self::shade($pending, $full, $height)];
    }

    /**
     * @param ?array{string, float, float} $pending
     * @return list<Mark>
     */
    private static function shade(?array $pending, float $top, float $height): array
    {
        if (null === $pending || 0.0 === $height || $pending[2] <= $pending[1]) {
            return [];
        }
        [$color, $x1, $x2] = $pending;

        return [new Mark(MarkKind::Polygon, [[$x1, $top], [$x2, $top], [$x2, $top + $height], [$x1, $top + $height]], stroke: 'none', fill: $color, strokeWidth: 0.0)];
    }

    /** @return list<Mark> the day lines of a chart without dates, a day or more apart, from 6 down */
    private function smallLines(float $total): array
    {
        $marks = [];
        for ($day = $this->minDay; $day <= ($this->maxDay + 1); $day += $this->step($day)) {
            $x = $this->scale->x(GanttTime::start($day));
            $marks[] = $this->line($x, 6.0, $x, 6.0 + $total + 2);
        }

        return $marks;
    }

    /** @return list<Mark> PlantUML's `drawSimpleDayCounter`: each day's number from 1, or week's, centred on it */
    private function counter(float $y): array
    {
        $marks = [];
        $weekly = 'weekly' === $this->chart->scale;
        for ($day = $this->minDay; $day <= ($this->maxDay + 1); $day += $this->step($day)) {
            if ($day >= ($this->maxDay + 1)) {
                continue;
            }
            $start = GanttTime::start($day);
            $value = $weekly ? \intdiv($day, num2: 7) + 1 : $day + 1;
            $x1 = $this->scale->x($start);
            $x2 = $weekly ? $this->scale->x(GanttTime::start($day + 6)) + $this->scale->width(GanttTime::start($day + 6)) : $this->scale->x(GanttTime::start($day + $this->step($day)));
            $text = new GanttText([(string) $value], $this->daySize, FontFace::Regular, $this->font);
            \array_push($marks, ...$text->marks($x1 + (($x2 - $x1 - $text->width()) / 2), $y));
        }

        return $marks;
    }

    /** As PlantUML's `TimeHeaderSimple.increment`: days enough to stand 16 apart, or a week. */
    private function step(int $day): int
    {
        if ('weekly' === $this->chart->scale) {
            return 7;
        }
        if (null === $this->delta) {
            $x = $this->scale->x(GanttTime::start($day));
            $this->delta = 0;
            do {
                ++$this->delta;
                ++$day;
            } while ($this->scale->x(GanttTime::start($day)) < ($x + 16) && $this->delta < 10_000);
        }

        return $this->delta;
    }

    /** @return list<Mark> the separators asked for, down the chart, as PlantUML's `printVerticalSeparators` */
    private function separators(float $total): array
    {
        $marks = [];
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            if (!\array_key_exists($day, $this->chart->separatorsBefore)) {
                continue;
            }
            $marks[] = $this->separator($this->scale->x(GanttTime::start($day)), $total);
        }

        return $marks;
    }

    /** @return list<Mark> a line before each day, a separator where asked or after a hidden day */
    private function dailySeparators(float $total): array
    {
        $full = $this->fullHeaderHeight();
        $marks = [];
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            $x = $this->scale->x(GanttTime::start($day));
            $marks[] = \array_key_exists($day, $this->chart->separatorsBefore) || 0.0 === $this->scale->width(GanttTime::start($day - 1))
                ? $this->separator($x, $total)
                : $this->line($x, $full, $x, $total);
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        $marks[] = $this->line($end, $full, $end, $total);

        return $marks;
    }

    /** @return list<Mark> a line at each week's first day, and at the end */
    private function weeklySeparators(float $total): array
    {
        $top = $this->monthSize + 4;
        $marks = [];
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            if (GanttTime::weekday($day) !== $this->chart->firstWeekday) {
                continue;
            }
            $x = $this->scale->x(GanttTime::start($day));
            $marks[] = $this->line($x, $top, $x, $total);
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));

        return [...$marks, $this->line($end, $top, $end, $total), ...$this->separators($total)];
    }

    private function separator(float $x, float $total): Mark
    {
        $style = $this->style('verticalSeparator');

        return new Mark(
            MarkKind::Polyline,
            [[$x, $this->fullHeaderHeight()], [$x, $total]],
            stroke: $style->color('LineColor', '#000000'),
            dashed: null !== $style->dashes(),
            strokeWidth: $style->number('LineThickness', 2.0),
            dashes: $style->dashes(),
        );
    }

    /**
     * @param \Closure(int): bool $hidden
     * @return list<Mark> each month's name centred over its days, as long as fits
     */
    private function months(float $y, \Closure $hidden): array
    {
        $marks = [];
        $last = null;
        $lastX = -1.0;
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            if ($hidden($day)) {
                continue;
            }
            $x = $this->scale->x(GanttTime::start($day));
            if (null === $last || GanttTime::date($day)[1] !== GanttTime::date($last)[1] || GanttTime::date($day)[0] !== GanttTime::date($last)[0]) {
                if (null !== $last) {
                    \array_push($marks, ...$this->centred($lastX, $x, $y, $last, $this->monthSize, FontFace::Bold, ['month_short', 'month_long', 'month_year_long']));
                }
                [$last, $lastX] = [$day, $x];
            }
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        if (null !== $last && $end > $lastX) {
            \array_push($marks, ...$this->centred($lastX, $end, $y, $last, $this->monthSize, FontFace::Bold, ['month_short', 'month_long', 'month_year_long']));
        }

        return $marks;
    }

    /** @return list<Mark> each weekday's two letters, grey when closed */
    private function weekdays(float $y): array
    {
        return $this->eachDay($y, ['dow_short']);
    }

    /** @return list<Mark> each day of the month, grey when closed */
    private function daysOfMonth(float $y): array
    {
        return $this->eachDay($y, ['dom']);
    }

    /**
     * @param list<string> $formats
     * @return list<Mark>
     */
    private function eachDay(float $y, array $formats): array
    {
        $marks = [];
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            if ($this->hidden($day)) {
                continue;
            }
            $start = GanttTime::start($day);
            $color = $this->chart->calendar->rate($day)->zeroed() ? $this->style('closed')->color('FontColor', '#989898') : $this->font;
            \array_push($marks, ...$this->centred($this->scale->x($start), $this->scale->x($start + GanttTime::DAY), $y, $day, $this->daySize, FontFace::Regular, $formats, color: $color));
        }

        return $marks;
    }

    private function hidden(int $day): bool
    {
        return $this->chart->hideClosed && $this->chart->calendar->isClosed($day);
    }

    /** @return list<Mark> each day's name, where it changes, under the calendar */
    private function namedDays(): array
    {
        if ([] === $this->chart->names) {
            return [];
        }
        $marks = [];
        $last = null;
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            $name = $this->chart->names[$day] ?? null;
            if (null !== $name && $name !== $last) {
                \array_push($marks, ...new GanttText([$name], $this->monthSize, FontFace::Regular, $this->font)->marks($this->scale->x(GanttTime::start($day)), $this->headerHeight()));
            }
            $last = $name;
        }

        return $marks;
    }

    /** @return list<Mark> each week's number, or its first day of the month, 5 into its first day */
    private function weekNumbers(): array
    {
        $marks = [];
        $counter = $this->chart->weekNumbering ?? 0;
        for ($day = $this->minDay; $day < ($this->maxDay - 1); ++$day) {
            if (GanttTime::weekday($day) !== $this->chart->firstWeekday) {
                continue;
            }
            $number = match (true) {
                null === $this->chart->weekNumbering => $this->weekOfYear($day),
                -1 === $this->chart->weekNumbering => GanttTime::date($day)[2],
                default => $counter++,
            };
            \array_push($marks, ...new GanttText([(string) $number], $this->daySize, FontFace::Regular, $this->font)->marks($this->scale->x(GanttTime::start($day)) + 5, $this->monthSize + 4));
        }

        return $marks;
    }

    /** As Java's `WeekFields.weekOfYear`, weeks starting on the chart's first weekday, the first holding as many days of the year as it asks. */
    private function weekOfYear(int $day): int
    {
        [$year] = GanttTime::date($day);
        $first = GanttTime::of($year, 1, 1);
        $offset = (GanttTime::weekday($first) - $this->chart->firstWeekday + 7) % 7;
        $weekStart = $first - $offset;
        $week = \intdiv($day - $weekStart, num2: 7);

        return (7 - $offset) >= $this->chart->minimalDaysInFirstWeek ? $week + 1 : $week;
    }

    /** @return list<Mark> each month's name, short or with its year, with a line at each month's start */
    private function weeklyMonths(float $y): array
    {
        $top = $this->monthSize + 4;
        $marks = [];
        $last = null;
        $lastX = -1.0;
        for ($day = $this->minDay; $day <= $this->maxDay; ++$day) {
            $x = $this->scale->x(GanttTime::start($day));
            if (null === $last || GanttTime::date($day)[1] !== GanttTime::date($last)[1] || GanttTime::date($day)[0] !== GanttTime::date($last)[0]) {
                $marks[] = $this->line($x, $y, $x, $y + $top);
                if (null !== $last) {
                    \array_push($marks, ...$this->centred($lastX, $x, $y, $last, $this->monthSize, FontFace::Bold, ['month_short', 'month_year_short']));
                }
                [$last, $lastX] = [$day, $x];
            }
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        $marks[] = $this->line($end, $y, $end, $y + $top);
        if (null !== $last && $end > $lastX) {
            \array_push($marks, ...$this->centred($lastX, $end, $y, $last, $this->monthSize, FontFace::Bold, ['month_short', 'month_year_short']));
        }

        return $marks;
    }

    /**
     * @param string $font `month` for the month font in bold, `year` for the year font in bold
     * @return list<Mark> each year, a line at its start
     */
    private function years(float $y, string $font): array
    {
        $size = 'year' === $font ? $this->yearSize : $this->monthSize;
        $marks = [];
        $last = null;
        $lastX = -1.0;
        $through = 'year' === $font ? $this->maxDay : $this->maxDay - 1;
        for ($day = $this->minDay; $day <= $through; ++$day) {
            $x = $this->scale->x(GanttTime::start($day));
            if (null === $last || GanttTime::date($day)[0] !== GanttTime::date($last)[0]) {
                $marks[] = $this->line($x, $y, $x, $y + $this->yearSize + 2);
                if (null !== $last) {
                    \array_push($marks, ...$this->centred($lastX, $x, $y, $last, $size, FontFace::Bold, ['year'], 'year' === $font ? 'hide' : 'shortest'));
                }
                [$last, $lastX] = [$day, $x];
            }
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        if (null !== $last && $end > $lastX) {
            \array_push($marks, ...$this->centred($lastX, $end, $y, $last, $size, FontFace::Bold, ['year'], 'year' === $font ? 'hide' : 'shortest'));
        }
        $marks[] = $this->line($end, $y, $end, $y + $this->yearSize + 2);

        return $marks;
    }

    /** @return list<Mark> each month or quarter, a line at its start */
    private function spans(float $y, string $kind): array
    {
        $marks = [];
        $last = null;
        $lastX = -1.0;
        $key = static fn (int $day): string => GanttTime::date($day)[0].('month' === $kind ? '-'.GanttTime::date($day)[1] : '-Q'.\intdiv(GanttTime::date($day)[1] + 2, num2: 3));
        $formats = 'month' === $kind ? ['month_short', 'month_long'] : ['quarter'];
        for ($day = $this->minDay; $day < $this->maxDay; ++$day) {
            $x = $this->scale->x(GanttTime::start($day));
            if (null === $last || $key($day) !== $key($last)) {
                $marks[] = $this->line($x, $y, $x, $y + $this->monthSize + 2);
                if (null !== $last) {
                    \array_push($marks, ...$this->centred($lastX, $x, $y, $last, $this->daySize, FontFace::Regular, $formats));
                }
                [$last, $lastX] = [$day, $x];
            }
        }
        $end = $this->scale->x(GanttTime::start($this->maxDay + 1));
        if (null !== $last && $end > $lastX) {
            \array_push($marks, ...$this->centred($lastX, $end, $y, $last, $this->daySize, FontFace::Regular, $formats));
        }
        $marks[] = $this->line($end, $y, $end, $y + $this->monthSize + 2);

        return $marks;
    }

    /**
     * PlantUML's `printCentered`: the longest of the forms that fits, as PlantUML measures it, or the shortest — or none, when too big is to `hide` — centred.
     *
     * @param list<string> $formats from the shortest
     * @return list<Mark>
     */
    private function centred(float $start, float $end, float $y, int $day, float $size, FontFace $face, array $formats, string $tooBig = 'shortest', ?string $color = null): array
    {
        $available = $end - $start;
        for ($index = \count($formats) - 1; $index >= 0; --$index) {
            $text = new GanttText([self::format($formats[$index], $day)], $size, $face, $color ?? $this->font);
            if ((0 === $index && 'shortest' === $tooBig) || $text->measured() <= $available) {
                return $text->marks($start + (\max(0.0, $available - $text->width()) / 2), $y);
            }
        }

        return [];
    }

    private static function format(string $format, int $day): string
    {
        [$year, $month, $date] = GanttTime::date($day);

        return match ($format) {
            'dow_short' => GanttTime::weekdayShort(GanttTime::weekday($day)),
            'dom' => (string) $date,
            'month_short' => GanttTime::monthShort($month),
            'month_long' => GanttTime::monthLong($month),
            'month_year_long' => GanttTime::monthLong($month).' '.$year,
            'month_year_short' => GanttTime::monthShort($month).' '.$year,
            'quarter' => 'Q'.\intdiv($month + 2, num2: 3),
            default => (string) $year,
        };
    }

    private function hline(float $y): Mark
    {
        return $this->line($this->scale->x(GanttTime::start($this->minDay)), $y, $this->scale->x(GanttTime::start($this->maxDay + 1)), $y);
    }

    private function line(float $x1, float $y1, float $x2, float $y2): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $this->line, strokeWidth: 1.0);
    }

    private function style(string ...$names): \Janalis\Viz\PlantUML\Style\Style
    {
        return $this->sheet->style(['root', 'element', 'ganttDiagram', ...$names]);
    }
}
