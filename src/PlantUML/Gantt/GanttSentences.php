<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A Gantt sentence carried out as PlantUML's verb phrases carry it out: its subject found —
 * the chart, today, a task made when new, a resource, days, a weekday, a day or a separator —
 * then each verb phrase's complement read and its action taken, each action as PlantUML's
 * anonymous one of the same name takes it.
 */
final class GanttSentences
{
    /**
     * @param array{subject: string, pattern: string, captures: list<string>, verbs: list<array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string}>} $subject
     * @throws PlantUmlRefusal when PlantUML fails on the sentence
     * @throws Unsupported when it is not drawn natively
     */
    public static function execute(GanttChart $chart, array $subject, string $line): void
    {
        $start = GanttGrammar::skip($line, 0);
        [$length, $captures] = GanttGrammar::match($subject['pattern'], $subject['captures'], $line, $start) ?? [0, []];
        $at = GanttGrammar::skip($line, $start + $length);
        $who = self::subject($chart, $subject['subject'], $captures);
        while (true) {
            $phrase = null;
            $verb = null;
            foreach ($subject['verbs'] as $candidate) {
                $phrase = GanttGrammar::phrase($candidate, $line, GanttGrammar::skip($line, $at));
                if (null !== $phrase) {
                    $verb = $candidate;
                    break;
                }
            }
            if (null === $phrase || null === $verb) {
                throw new PlantUmlRefusal('No matching verb phrase');
            }
            [$at, $words, $written] = $phrase;
            self::act($chart, $verb['action'], $who, $words, $written);
            $next = GanttGrammar::and($line, $at);
            if (null === $next) {
                return;
            }
            $at = $next;
        }
    }

    /**
     * @param array<string, string> $captures
     * @return GanttChart|GanttEntry|GanttResource|int|list<int>|string the subject, as its `getMe`
     */
    private static function subject(GanttChart $chart, string $kind, array $captures): GanttChart|GanttEntry|GanttResource|int|array|string
    {
        return match ($kind) {
            'SubjectGantt', 'SubjectSeparator' => $chart,
            'SubjectToday' => 'today',
            'SubjectTask' => self::task($chart, $captures),
            'SubjectResource' => self::resource($chart, $captures),
            'SubjectDaysAsDates' => self::days($chart, $captures),
            'SubjectDayOfWeek' => GanttTime::weekdayNamed($captures['SUBJECT'] ?? ''),
            'SubjectDayAsDate' => self::dayAsDate($chart, $captures),
            default => throw new Unsupported('The native PlantUML renderer leaves Gantt working hours to PlantUML'),
        };
    }

    /** @param array<string, string> $captures */
    private static function task(GanttChart $chart, array $captures): GanttEntry
    {
        if (\array_key_exists('IT', $captures)) {
            return $chart->it ?? throw new PlantUmlRefusal('Not sure what are you refering to?');
        }
        $display = $captures['SUBJECT'] ?? '';
        $task = $chart->taskOrNew($captures['SHORTNAME'] ?? $display, $display, \array_key_exists('THEN', $captures) ? $chart->lastTask() : null);
        if (\array_key_exists('STEREOTYPE', $captures)) {
            $task->stereotypes = ClassReader::stereotypes($captures['STEREOTYPE']);
        }
        if ($task instanceof GanttTask) {
            $chart->it = $task;
        }
        $parts = \preg_split('/[{}]/', $captures['RESOURCE'] ?? '');
        foreach (false === $parts ? [] : $parts as $part) {
            $part = \trim($part);
            if ('' === $part) {
                continue;
            }
            if (!$task instanceof GanttTask || !$chart->assign($task, $part)) {
                throw new PlantUmlRefusal('Bad argument for resource');
            }
        }

        return $task;
    }

    /** @param array<string, string> $captures */
    private static function resource(GanttChart $chart, array $captures): GanttResource
    {
        if (\array_key_exists('THEY', $captures)) {
            return $chart->they ?? throw new PlantUmlRefusal('Not sure who are you refering to?');
        }
        $chart->they = $chart->resource($captures['RESOURCE'] ?? '');

        return $chart->they;
    }

    /**
     * As PlantUML's `SubjectDaysAsDates`: from one day to another, or a number of open days from one, or from the last day named or coloured.
     *
     * @param array<string, string> $captures
     * @return list<int>
     */
    private static function days(GanttChart $chart, array $captures): array
    {
        if (\array_key_exists('COUNT_AND', $captures)) {
            return self::openDays($chart, self::subjectDay($chart, $captures, '3'), (int) $captures['COUNT_AND']);
        }
        if (\array_key_exists('COUNT_THEN', $captures)) {
            return self::openDays($chart, $chart->thenDay(), (int) $captures['COUNT_THEN']);
        }

        return \range(self::subjectDay($chart, $captures, '1'), self::subjectDay($chart, $captures, '2'));
    }

    /**
     * As PlantUML's `DaysAsDates(gantt, date, count)`: until as many open days are past, and the day after.
     *
     * @return list<int>
     */
    private static function openDays(GanttChart $chart, int $first, int $count): array
    {
        $day = $first;
        while ($count > 0) {
            if ($chart->isOpen($day)) {
                --$count;
            }
            ++$day;
        }

        return \range($first, $day);
    }

    /** @param array<string, string> $captures */
    private static function subjectDay(GanttChart $chart, array $captures, string $suffix): int
    {
        if (\array_key_exists('BDAY'.$suffix, $captures)) {
            return GanttTime::of((int) ($captures['BYEAR'.$suffix] ?? 0), (int) ($captures['BMONTH'.$suffix] ?? 0), (int) $captures['BDAY'.$suffix]);
        }

        return $chart->minDay + (int) ($captures['ECOUNT'.$suffix] ?? 0);
    }

    /** @param array<string, string> $captures */
    private static function dayAsDate(GanttChart $chart, array $captures): int
    {
        if (\array_key_exists('BDAY', $captures)) {
            return GanttTime::of((int) ($captures['BYEAR'] ?? 0), (int) ($captures['BMONTH'] ?? 0), (int) $captures['BDAY']);
        }
        $count = (int) ($captures['ECOUNT'] ?? 0) * ('-' === ($captures['EOPERATION'] ?? '+') ? -1 : 1);

        return $count
        + match (\strtoupper($captures['ETYPE'] ?? 'D')) {
            'T' => $chart->todayDay(),
            'E' => $chart->maxDay(),
            default => $chart->minDay,
        };
    }

    /**
     * Each verb phrase's action, by the name PlantUML gives it.
     *
     * @param GanttChart|GanttEntry|GanttResource|int|list<int>|string $who
     * @param array<string, string> $words
     */
    private static function act(GanttChart $chart, string $action, GanttChart|GanttEntry|GanttResource|int|array|string $who, array $words, string $written): void
    {
        match (true) {
            'SubjectGantt$1' === $action => $chart->start(self::date($chart, $words)),
            'SubjectToday$1' === $action => $chart->todayColor = self::colors($words, 'COMPLEMENT1', 'COMPLEMENT2')[0],
            'SubjectToday$2' === $action => $chart->today = self::date($chart, $words),
            \str_starts_with($action, 'SubjectTask$') => self::taskAction($chart, (int) \substr($action, offset: 12), self::asTask($who), $words, $written),
            \str_starts_with($action, 'SubjectResource$') => self::resourceAction(
                $chart,
                (int) \substr($action, offset: 16),
                $who instanceof GanttResource ? $who : throw new PlantUmlRefusal('No resource'),
                $words,
            ),
            \str_starts_with($action, 'SubjectDaysAsDates$'), \str_starts_with($action, 'SubjectDayAsDate$') => self::dayAction(
                $chart,
                $action,
                \is_array($who) ? $who : [\is_int($who) ? $who : 0],
                $words,
            ),
            \str_starts_with($action, 'SubjectDayOfWeek$') => self::weekdayAction($chart, (int) \substr($action, offset: 17), \is_int($who) ? $who : 1, $words),
            'SubjectSeparator$1' === $action => $chart->separatorsBefore[self::date($chart, $words)] = true,
            'SubjectSeparator$2' === $action => $chart->separatorsBefore[self::date($chart, $words) + 1] = true,
            // The last of the actions the grammar names: a separator just at an instant.
            default => $chart->separatorsBefore[GanttTime::day(self::instant($chart, $words)->at())] = true,
        };
    }

    /** @param array<string, string> $words */
    private static function taskAction(GanttChart $chart, int $index, GanttTask $task, array $words, string $written): void
    {
        match ($index) {
            1 => $task->setLoad(self::duration($chart, $written)),
            2, 6 => $task->setStart(self::absolute($chart, self::moment($chart, $words))),
            3, 4 => self::startAt($chart, $task, self::instant($chart, $words), 3 === $index ? $words : null),
            5 => $task->setStart(GanttTime::start(self::date($chart, $words))),
            7 => $task->colors = [self::colors($words, 'COMPLEMENT1', 'COMPLEMENT2')],
            8 => $task->colors = [self::colors($words, 'FROM1', 'FROM2'), self::colors($words, 'TO1', 'TO2')],
            9 => self::happens($task, GanttTime::start(self::date($chart, $words))),
            10 => self::happensAt($task, self::instant($chart, $words)),
            11 => self::occurs($chart, $task, $words),
            12 => $task->setEnd(self::absolute($chart, self::moment($chart, $words))),
            13 => self::endAt($chart, $task, self::instant($chart, $words)),
            14 => $task->setEnd(GanttTime::start(self::date($chart, $words) + 1)),
            15 => $task->setEnd(self::absolute($chart, GanttTime::start(self::date($chart, $words) + 1))),
            16 => $task->sameRowAs($chart->task($words['COMPLEMENT'] ?? '') ?? throw new PlantUmlRefusal('No such task null')),
            17 => $task->colors = [['#ffffff', '#000000']],
            18 => $task->completion = (int) ($words['COMPLEMENT'] ?? 0),
            19 => self::pause($task, self::interval($chart, $words)),
            20 => self::pause($task, self::smartInterval($chart, $words)),
            21 => self::pause($task, [self::date($chart, $words)]),
            22 => $task->pausedWeekdays[GanttTime::weekdayNamed($words['COMPLEMENT'] ?? '')] = true,
            23 => null,
            // The last of the phrases the grammar names: is displayed as.
            default => $task->displayString = $words['ANYTHING'] ?? '',
        };
    }

    /** @param array<string, string> $words */
    private static function resourceAction(GanttChart $chart, int $index, GanttResource $resource, array $words): void
    {
        $calendar = $resource->calendar;
        match ($index) {
            1 => self::asTask($chart->task($words['COMPLEMENT'] ?? '') ?? throw new PlantUmlRefusal('No such task '.($words['COMPLEMENT'] ?? '')))->addResource($resource, 100),
            2 => $calendar->offBefore = self::date($chart, $words),
            3 => $calendar->offAfter = self::date($chart, $words),
            4 => \array_map($calendar->closeDay(...), self::interval($chart, $words)),
            5 => $calendar->closeWeekday(GanttTime::weekdayNamed($words['COMPLEMENT'] ?? '')),
            6 => $calendar->closeDay(self::date($chart, $words)),
            7 => \array_map($calendar->openDay(...), self::interval($chart, $words)),
            // The last of the phrases the grammar names: is on a day.
            default => $calendar->openDay(self::date($chart, $words)),
        };
    }

    /**
     * @param list<int> $days
     * @param array<string, string> $words
     */
    private static function dayAction(GanttChart $chart, string $action, array $days, array $words): void
    {
        $kind = \substr($action, (int) \strrpos($action, needle: '$') + 1);
        $many = \str_starts_with($action, 'SubjectDaysAsDates');
        $task = self::forTask($words['CLOSED'] ?? $words['OPEN'] ?? '');
        foreach ($days as $day) {
            // The phrases the grammar names: closed, opened, coloured, and for days together named.
            match ($many ? ['1' => 'close', '2' => 'open', '3' => 'color'][$kind] ?? 'name' : ['1' => 'open', '2' => 'close'][$kind] ?? 'color') {
                'close' => '' === $task ? $chart->calendar->closeDay($day) : $chart->taskCalendar($task)->closeDay($day),
                'open' => '' === $task ? $chart->calendar->openDay($day) : $chart->taskCalendar($task)->openDay($day),
                'color' => $chart->colors[$day] = self::colors($words, 'COMPLEMENT1', 'COMPLEMENT2')[0] ?? '#ffffff',
                default => $chart->names[$day] = $words['COMPLEMENT'] ?? '',
            };
        }
    }

    /** @param array<string, string> $words */
    private static function weekdayAction(GanttChart $chart, int $index, int $weekday, array $words): void
    {
        $task = self::forTask($words['CLOSED'] ?? $words['OPEN'] ?? '');
        match ($index) {
            // As PlantUML, which opens a weekday for a task alone, but closes it for every task.
            1 => '' === $task ? $chart->calendar->openWeekday($weekday) : $chart->taskCalendar($task)->openWeekday($weekday),
            2 => $chart->calendar->closeWeekday($weekday),
            // The last of the phrases the grammar names: coloured.
            default => $chart->weekdayColors[$weekday] = self::colors($words, 'COMPLEMENT1', 'COMPLEMENT2')[0] ?? '#ffffff',
        };
    }

    /** @param ?array<string, string> $link */
    private static function startAt(GanttChart $chart, GanttTask $task, GanttInstant $when, ?array $link): void
    {
        $task->setStart($when->at());
        if (!$when->isTask()) {
            return;
        }
        $color = null === $link ? null : self::color($link['COLOR'] ?? null);
        $chart->constraints[] = new GanttConstraint($when, new GanttInstant($task, end: false), $color, null === $link ? null : $link['STYLE'] ?? null);
    }

    private static function endAt(GanttChart $chart, GanttTask $task, GanttInstant $when): void
    {
        $task->setEnd($when->at());
        $chart->constraints[] = new GanttConstraint($when, new GanttInstant($task, end: true));
    }

    private static function happens(GanttTask $task, int $start): void
    {
        $task->setLoad(GanttTime::DAY);
        $task->setStart($start);
        $task->diamond = true;
    }

    private static function happensAt(GanttTask $task, GanttInstant $when): void
    {
        $task->setLoad(GanttTime::DAY);
        $task->diamond = true;
        $task->setStart($when->end ? $when->at() - GanttTime::DAY : $when->at());
    }

    /** @param array<string, string> $words */
    private static function occurs(GanttChart $chart, GanttTask $task, array $words): void
    {
        $from = $chart->task($words['COMPLEMENT1'] ?? '') ?? throw new PlantUmlRefusal('No such '.($words['COMPLEMENT1'] ?? '').' task');
        $to = $chart->task($words['COMPLEMENT2'] ?? '') ?? throw new PlantUmlRefusal('No such '.($words['COMPLEMENT2'] ?? '').' task');
        $task->setStart($from->start());
        $task->setEnd($to->end());
        $chart->constraints[] = new GanttConstraint(new GanttInstant($from, end: false), new GanttInstant($task, end: false));
        $chart->constraints[] = new GanttConstraint(new GanttInstant($to, end: true), new GanttInstant($task, end: true));
    }

    /** @param list<int> $days */
    private static function pause(GanttTask $task, array $days): void
    {
        foreach ($days as $day) {
            $task->pausedDays[$day] = true;
        }
    }

    /**
     * As PlantUML's `ComplementBeforeOrAfterOrAtTaskStartOrEnd`: a moment's start or end, some days, or weeks of open days, after or before it.
     *
     * @param array<string, string> $words
     */
    private static function instant(GanttChart $chart, array $words): GanttInstant
    {
        $code = $words['COMPLEMENT_CODE_OTHER'] ?? '';
        $moment = $chart->moment($code) ?? throw new PlantUmlRefusal('No such task '.$code);
        $end = 'end' === \strtolower($words['COMPLEMENT_START_OR_END'] ?? 'start');
        if (!\array_key_exists('COMPLEMENT_NB1', $words)) {
            return new GanttInstant($moment, $end);
        }
        $week = $chart->calendar->daysInWeek();
        $delta =
            ((int) $words['COMPLEMENT_NB1'] * (\str_starts_with(\strtolower($words['COMPLEMENT_DAY_OR_WEEK1'] ?? 'd'), 'w') ? $week : 1))
            + ((int) ($words['COMPLEMENT_NB2'] ?? 0) * (\str_starts_with(\strtolower($words['COMPLEMENT_DAY_OR_WEEK2'] ?? 'd'), 'w') ? $week : 1));
        if ('before' === \strtolower($words['COMPLEMENT_BEFORE_OR_AFTER'] ?? '')) {
            $delta = -$delta;
        }
        $working = \array_key_exists('COMPLEMENT_WORKING1', $words) || \array_key_exists('COMPLEMENT_WORKING2', $words);

        return new GanttInstant($moment, $end, $delta, $working ? $chart->plan() : null);
    }

    /**
     * As PlantUML's `ComplementDate`: a date written, or a number of days from the first day.
     *
     * @param array<string, string> $words
     */
    private static function date(GanttChart $chart, array $words, string $suffix = ''): int
    {
        return self::day($words, $suffix) ?? ($chart->minDay + (int) ($words['DCOUNT'.$suffix] ?? $words['ECOUNT'.$suffix] ?? 0));
    }

    /**
     * As PlantUML's `DayPattern`: `1 March 2024`, `2024-03-01` or `March 1 2024`.
     *
     * @param array<string, string> $words
     */
    private static function day(array $words, string $suffix): ?int
    {
        return match (true) {
            \array_key_exists('ADAY'.$suffix, $words) => GanttTime::of((int) ($words['AYEAR'.$suffix] ?? 0), GanttTime::monthNamed($words['AMONTH'.$suffix] ?? ''), (int) $words['ADAY'.$suffix]),
            \array_key_exists('BDAY'.$suffix, $words) => GanttTime::of((int) ($words['BYEAR'.$suffix] ?? 0), (int) ($words['BMONTH'.$suffix] ?? 0), (int) $words['BDAY'.$suffix]),
            \array_key_exists('CDAY'.$suffix, $words) => GanttTime::of((int) ($words['CYEAR'.$suffix] ?? 0), GanttTime::monthNamed($words['CMONTH'.$suffix] ?? ''), (int) $words['CDAY'.$suffix]),
            default => null,
        };
    }

    /**
     * As PlantUML's `ComplementTimePoint`: a date and a time.
     *
     * @param array<string, string> $words
     */
    private static function moment(GanttChart $chart, array $words): int
    {
        if (!\array_key_exists('HOUR', $words)) {
            return GanttTime::start(self::date($chart, $words));
        }

        return GanttTime::start(self::day($words, '') ?? $chart->minDay) + ((int) $words['HOUR'] * 3600) + ((int) ($words['MINUTE'] ?? 0) * 60) + (int) ($words['SECOND'] ?? 0);
    }

    /**
     * As PlantUML's `ComplementIntervals`: from one date to another, or day numbers.
     *
     * @return list<int>
     * @param array<string, string> $words
     */
    private static function interval(GanttChart $chart, array $words): array
    {
        $first = self::day($words, '1');
        if (null !== $first) {
            return \range($first, self::day($words, '2') ?? $first);
        }

        return \range($chart->minDay + (int) ($words['ECOUNT1'] ?? 0), $chart->minDay + (int) ($words['ECOUNT2'] ?? 0));
    }

    /**
     * As PlantUML's `ComplementIntervalsSmart`: from a date to a task's start, or the day before its end.
     *
     * @return list<int>
     * @param array<string, string> $words
     */
    private static function smartInterval(GanttChart $chart, array $words): array
    {
        $when = self::instant($chart, $words);
        $last = $when->at() - ($when->end ? GanttTime::DAY : 0);

        return \range(self::day($words, '1') ?? $chart->minDay, GanttTime::day($last));
    }

    /** As PlantUML's `ComplementDuration`: each number of hours, minutes, seconds, days, weeks of open days, or months of 30 days. */
    private static function duration(GanttChart $chart, string $written): int
    {
        $matches = [];
        \preg_match_all('/([0-9_]+) +(hour|minute|second|day|week|month)/i', $written, $matches, \PREG_SET_ORDER);
        $seconds = 0;
        foreach ($matches as [, $count, $unit]) {
            $seconds += (int) $count
            * match (\strtolower($unit)) {
                'hour' => 3600,
                'minute' => 60,
                'second' => 1,
                'week' => $chart->calendar->daysInWeek() * GanttTime::DAY,
                'month' => 30 * GanttTime::DAY,
                default => GanttTime::DAY,
            };
        }

        return $seconds;
    }

    /** A time that needs the chart's first day, as PlantUML refuses one without. */
    private static function absolute(GanttChart $chart, int $time): int
    {
        if ($chart->relative()) {
            throw new PlantUmlRefusal('No starting date for the project');
        }

        return $time;
    }

    /**
     * @return array{?string, ?string} a centre and a border colour, as PlantUML's `getColorOrWhite` reads them
     *
     * @param array<string, string> $words
     */
    private static function colors(array $words, string $center, string $border): array
    {
        return [self::color($words[$center] ?? null), self::color($words[$border] ?? null)];
    }

    private static function color(?string $written): ?string
    {
        return null === $written ? null : PlantUmlColor::parse($written) ?? '#ffffff';
    }

    /** The task in `closed for [task]`, or nothing. */
    private static function forTask(string $written): string
    {
        $match = [];

        return 1 === \preg_match('/\[(.*)\]/', $written, $match) && (int) \strpos($written, needle: '[') > 0 ? $match[1] : '';
    }

    private static function asTask(mixed $who): GanttTask
    {
        return $who instanceof GanttTask ? $who : throw new PlantUmlRefusal('Only a task does that');
    }
}
