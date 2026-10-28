<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * PlantUML's `TaskImpl`: a task, its start, end and load kept as its `Solver` keeps them —
 * the last two set, the third worked out by integrating the load over the days it may be
 * worked: the chart's open days, its own, less its pauses; or its resources' days, each at
 * its share. A start is never set earlier than it was; a start falling on a day off moves to
 * the next day on. Its start and end, once worked out, are kept until it changes itself, as
 * `TaskImpl` caches them: a day closed after, for the whole chart, leaves them as they were.
 */
final class GanttTask extends GanttEntry implements GanttMoment
{
    /** @var array<string, int> the start, end and load set, the last two of them, in the order set */
    private array $solver = [];
    /** @var list<array{GanttResource, int}> each resource and its share, in percent */
    public array $resources = [];
    /** @var array<int, true> */
    public private(set) array $pausedDays = [];
    /** @var array<int, true> */
    public private(set) array $pausedWeekdays = [];
    private ?int $cachedStart = null;
    private ?int $cachedEnd = null;
    public bool $diamond = false;
    public int $completion;
    /** @var list<array{?string, ?string}> one colour, or two for its completion, each a centre and a border */
    public array $colors = [];
    /** @var ?list<string> */
    public ?array $note = null;

    /** @param \Closure(): \Closure(int): GanttRate $plan the chart's open days, for this task */
    public function __construct(
        string $id,
        string $display,
        int $start,
        int $completion,
        int $day,
        private readonly \Closure $plan,
    ) {
        parent::__construct($id, $display);
        $this->completion = $completion;
        $this->set('start', $start);
        $this->set('load', $day);
    }

    public function setStart(int $start): void
    {
        $this->set('start', $start);
    }

    public function setEnd(int $end): void
    {
        $this->set('end', $end);
    }

    public function setLoad(int $load): void
    {
        $this->set('load', $load);
    }

    public function pauseDay(int $day): void
    {
        $this->pausedDays[$day] = true;
        $this->forget();
    }

    public function pauseWeekday(int $weekday): void
    {
        $this->pausedWeekdays[$weekday] = true;
        $this->forget();
    }

    /** As PlantUML's `TaskImpl.getStart`, kept once worked out. */
    #[\Override]
    public function start(): int
    {
        return $this->cachedStart ??= $this->workedStart();
    }

    private function workedStart(): int
    {
        $start = $this->solver['start'] ?? GanttIntegrator::backward($this->solver['end'] ?? 0, $this->solver['load'] ?? GanttTime::DAY, $this->rate());
        if ($this->diamond) {
            return $start;
        }
        $rate = $this->rate();
        for ($count = 0; $count < 9999 && $rate(GanttTime::day($start))->zeroed(); ++$count) {
            $start += GanttTime::DAY;
        }

        return $start;
    }

    /** As PlantUML's `TaskImpl.getEnd`, kept once worked out. */
    #[\Override]
    public function end(): int
    {
        return $this->cachedEnd ??= $this->solver['end'] ?? GanttIntegrator::forward($this->solver['start'] ?? 0, $this->solver['load'] ?? GanttTime::DAY, $this->rate());
    }

    /** The day after its last day, at its start: its end less a day, as PlantUML's `getEndMinusOneDayTOBEREMOVED`. */
    public function lastMoment(): int
    {
        return $this->end() - GanttTime::DAY;
    }

    /** @return \Closure(int): GanttRate as PlantUML's `asPiecewiseConstant` */
    public function rate(): \Closure
    {
        $plan = ($this->plan)();
        $pause = $this->pause(...);
        if ([] === $this->resources) {
            return static fn (int $day): GanttRate => $plan($day)->times($pause($day));
        }
        $resources = $this->resources;

        return static function (int $day) use ($plan, $pause, $resources): GanttRate {
            $sum = GanttRate::zero();
            foreach ($resources as [$resource, $share]) {
                $sum = $sum->plus($resource->calendar->rate($day)->times(new GanttRate($share, 100)));
            }

            return $plan($day)->times($pause($day)->times($sum));
        };
    }

    /** @return \Closure(int): GanttRate the chart's open days, for this task */
    public function plan(): \Closure
    {
        return ($this->plan)();
    }

    /** Its own pauses: none on a day it pauses, by date or by weekday. */
    public function pause(int $day): GanttRate
    {
        return \array_key_exists($day, $this->pausedDays) || \array_key_exists(GanttTime::weekday($day), $this->pausedWeekdays) ? GanttRate::zero() : GanttRate::one();
    }

    /** @return list<int> the days it pauses, by date or by weekday within it */
    public function pausedDays(): array
    {
        $days = \array_keys($this->pausedDays);
        if ([] !== $this->pausedWeekdays) {
            for ($day = GanttTime::day($this->start()), $last = GanttTime::day($this->lastMoment()); $day <= $last; ++$day) {
                if (!\array_key_exists(GanttTime::weekday($day), $this->pausedWeekdays)) {
                    continue;
                }
                $days[] = $day;
            }
        }
        $days = \array_values(\array_unique($days));
        \sort($days);

        return $days;
    }

    /** As PlantUML's `getPrettyDisplay`: its name, and its resources, each with its share when not all of it. */
    public function prettyDisplay(): string
    {
        if ([] === $this->resources) {
            return $this->display;
        }
        $parts = \array_map(static fn (array $resource): string => '{'.$resource[0]->name.(100 === $resource[1] ? '' : ':'.$resource[1].'%').'}', $this->resources);

        return $this->display.' '.\implode(' ', $parts);
    }

    /** Its share of a resource's day: none outside it, on a pause or on the resource's day off. */
    public function loadFor(GanttResource $resource, int $day): int
    {
        foreach ($this->resources as [$assigned, $share]) {
            if ($assigned !== $resource) {
                continue;
            }
            $moment = GanttTime::start($day);
            if ($moment < $this->start() || $moment > $this->lastMoment() || $this->pause($day)->zeroed() || $resource->calendar->isClosed($day)) {
                return 0;
            }

            return $share;
        }

        return 0;
    }

    public function addResource(GanttResource $resource, int $share): void
    {
        foreach ($this->resources as $index => [$assigned]) {
            if ($assigned !== $resource) {
                continue;
            }
            $this->resources[$index] = [$resource, $share];
            $this->forget();

            return;
        }
        $this->resources[] = [$resource, $share];
        $this->forget();
    }

    /** As `invalidateCache`: its start and end worked out again. */
    private function forget(): void
    {
        [$this->cachedStart, $this->cachedEnd] = [null, null];
    }

    /** As PlantUML's `Solver.setData`: a start never earlier than one set before; only the last two kept. */
    private function set(string $attribute, int $value): void
    {
        $previous = $this->solver[$attribute] ?? null;
        unset($this->solver[$attribute]);
        if ('start' === $attribute && null !== $previous && $previous > $value) {
            $value = $previous;
        }
        $this->solver[$attribute] = $value;
        if (\count($this->solver) > 2) {
            \array_shift($this->solver);
        }
        $this->forget();
    }
}
