<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A Gantt chart as PlantUML's `GanttDiagram` keeps it while its lines are read: its tasks,
 * separators and groups in order, its resources and arrows; its calendar, the days named and
 * coloured; its first day — 1970-01-01 when none is given, as PlantUML counts days then —
 * and how it is printed.
 */
final class GanttChart
{
    /** @var array<string, GanttEntry> by code, in the order made */
    public array $entries = [];
    /** @var array<string, GanttResource> by name */
    public array $resources = [];
    /** @var list<GanttConstraint> */
    public array $constraints = [];
    public GanttCalendar $calendar;
    /** @var array<string, GanttCalendar> each task's own open and closed days, by its code */
    public array $taskCalendars = [];
    /** @var array<int, string> each day's name */
    public array $names = [];
    /** @var array<int, string> each day's colour */
    public array $colors = [];
    /** @var array<int, string> each weekday's colour */
    public array $weekdayColors = [];
    /** @var array<int, true> the days with a separator before them */
    public array $separatorsBefore = [];
    public int $minDay = 0;
    public ?int $printStart = null;
    public ?int $printEnd = null;
    public ?int $today = null;
    public ?string $todayColor = null;
    public string $scale = 'daily';
    public float $zoom = 1.0;
    public bool $hideClosed = false;
    public bool $footbox = true;
    public bool $hideResourceNames = false;
    public bool $hideResourceFootbox = false;
    /** @var list<string> the task table's columns, as PlantUML lists them */
    public array $columns = ['START', 'END', 'DURATION'];
    /** 0 for weeks numbered in the year, or the first number, or -1 for weeks by their first day of the month */
    public ?int $weekNumbering = null;
    public int $firstWeekday = 1;
    public int $minimalDaysInFirstWeek = 4;
    public int $defaultCompletion = 100;
    public ?GanttTask $it = null;
    public ?GanttResource $they = null;
    private ?GanttGroup $group = null;

    public function __construct()
    {
        $this->calendar = new GanttCalendar();
    }

    /** Whether no first day was given, so that days are counted from the first. */
    public function relative(): bool
    {
        return 0 === $this->minDay;
    }

    public function task(string $id): ?GanttTask
    {
        $entry = $this->entries[$id] ?? null;

        return $entry instanceof GanttTask ? $entry : null;
    }

    /** As PlantUML's `getOrCreateTask`: a new task after another when given one. */
    public function taskOrNew(string $id, string $display, ?GanttTask $previous = null): GanttEntry
    {
        $existing = $this->entries[$id] ?? null;
        if (null !== $existing) {
            return $existing;
        }
        $task = new GanttTask($id, $display, GanttTime::start($this->minDay), $this->defaultCompletion, GanttTime::DAY, fn (): \Closure => $this->planFor($id));
        if (null !== $this->group) {
            $this->group->children[] = $task;
        }
        $this->entries[$id] = $task;
        if (null !== $previous) {
            $this->order($previous, $task);
        }

        return $task;
    }

    /** As PlantUML's `forceTaskOrder`: the second starting at the first's end, an arrow between them. */
    public function order(GanttTask $first, GanttTask $second): GanttConstraint
    {
        $end = new GanttInstant($first, end: true);
        $second->setStart($end->at());
        $constraint = new GanttConstraint($end, new GanttInstant($second, end: false));
        $this->constraints[] = $constraint;

        return $constraint;
    }

    public function separator(?string $comment): void
    {
        $separator = new GanttSeparator(\count($this->entries), $comment);
        $this->entries[$separator->id] = $separator;
    }

    public function openGroup(string $name): void
    {
        $group = new GanttGroup($name, $this->group);
        if (null !== $this->group) {
            $this->group->children[] = $group;
        }
        $this->group = $group;
        $this->entries[$name] = $group;
    }

    public function closeGroup(): void
    {
        $this->group = ($this->group ?? throw new PlantUmlRefusal('No group to be closed'))->parent;
    }

    public function start(int $day): void
    {
        if ([] !== $this->entries) {
            throw new PlantUmlRefusal('Starting point must be set before task definition');
        }
        $this->minDay = $day;
    }

    public function resource(string $name): GanttResource
    {
        return $this->resources[$name] ??= new GanttResource($name);
    }

    /** As PlantUML's `affectResource`: `name` or `name:50`; false for a share of 0. */
    public function assign(GanttTask $task, string $description): bool
    {
        $match = [];
        \preg_match('/^([^:]+)(:(\d+))?/', $description, $match);
        $share = \array_key_exists(3, $match) ? (int) $match[3] : 100;
        if (0 === $share) {
            return false;
        }
        $task->addResource($this->resource($match[1] ?? $description), $share);

        return true;
    }

    /** As PlantUML's `getExistingMoment`: a task or a group, or days named alike, ignoring case. */
    public function moment(string $id): ?GanttMoment
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry instanceof GanttMoment) {
            return $entry;
        }
        $days = \array_keys(\array_filter($this->names, static fn (string $name): bool => 0 === \strcasecmp($name, $id)));
        if ([] === $days) {
            return null;
        }

        return new GanttSpan(GanttTime::start(\min($days)), GanttTime::start(\max($days) + 1));
    }

    /** @return \Closure(int): GanttRate the chart's open days */
    public function plan(): \Closure
    {
        $calendar = $this->calendar;

        return $calendar->rate(...);
    }

    /** @return \Closure(int): GanttRate as PlantUML's `getLoadPlanableForTask`: the chart's open days, with the task's own over them */
    public function planFor(string $id): \Closure
    {
        return $this->calendar->with($this->taskCalendars[$id] ?? null)->rate(...);
    }

    public function taskCalendar(string $id): GanttCalendar
    {
        return $this->taskCalendars[$id] ??= new GanttCalendar();
    }

    public function isOpen(int $day): bool
    {
        return !$this->calendar->rate($day)->zeroed();
    }

    /** As PlantUML's `getThenDate`: the last day named or coloured, or the first day. */
    public function thenDay(): int
    {
        return \max([$this->minDay, ...\array_keys($this->colors), ...\array_keys($this->names)]);
    }

    /** As PlantUML's `initMinMax`: the last day of any task, or of any day coloured or named after. */
    public function maxDay(): int
    {
        $max = null;
        foreach ($this->entries as $entry) {
            if (!$entry instanceof GanttTask) {
                continue;
            }
            $max = \max($max ?? \PHP_INT_MIN, GanttTime::day($entry->end() - 1));
        }
        $max ??= $this->minDay;
        foreach ([...\array_keys($this->colors), ...\array_keys($this->names)] as $day) {
            $max = \max($max, $day);
        }

        return $max;
    }

    public function todayDay(): int
    {
        return $this->today ?? GanttTime::day(\time());
    }

    /**
     * As PlantUML's `addNote`: under the last row.
     *
     * @param list<string> $lines
     */
    public function note(array $lines): void
    {
        $last = \array_key_last($this->entries);
        if (null === $last) {
            throw new PlantUmlRefusal('No task defined');
        }
        $entry = $this->entries[$last];
        if ($entry instanceof GanttTask) {
            $entry->note = $lines;
        }
    }

    /** The task made last, as PlantUML's `getLastCreatedTask`. */
    public function lastTask(): ?GanttTask
    {
        foreach (\array_reverse($this->entries) as $entry) {
            if ($entry instanceof GanttTask) {
                return $entry;
            }
        }

        return null;
    }
}
