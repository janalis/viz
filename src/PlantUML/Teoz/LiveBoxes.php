<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceParticipant;
use Janalis\Viz\PlantUML\Teoz\Rose\ActiveLine;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;

/**
 * A participant's activations, as teoz stacks them: how deep it is active at each event,
 * looking ahead to the changes of life a message carries, and the boxes drawn level by
 * level from where each event touches the lifeline, cut by delays, with a cross where it
 * is destroyed.
 */
final class LiveBoxes
{
    /** @var array<int, float> where each event touches the lifeline, by its object id */
    private array $steps = [];
    /** @var ?list<int> each event's level of activation once its own changes of life are counted */
    private ?array $levels = null;
    /** @var list<int> from each place in time, the next change of life before anything but a note or a message, or -1 */
    private array $nextLife = [];
    /** @var list<array{float, float}> each delay's top and height */
    private array $delays = [];

    public function __construct(
        private readonly SequenceParticipant $participant,
        private readonly TeozEvents $events,
        private readonly Rose $rose,
    ) {}

    /** Where an event dealing with the participant touches its lifeline; a deactivation where another event already does, 5 lower. */
    public function addStep(SequenceEvent $event, float $y): void
    {
        if ($event instanceof SequenceLife && 'deactivate' === $event->type && \in_array($y, $this->steps, strict: true)) {
            $y += 5.0;
        }
        $this->steps[\spl_object_id($event)] = $y;
    }

    public function delayOn(float $y, float $height): void
    {
        $this->delays[] = [$y, $height];
    }

    /** How deep the participant is active at an event, looking ahead at the changes of life it carries as a mode says. */
    public function levelAt(SequenceEvent $event, History $mode): int
    {
        $this->cache();
        $index = $this->events->place($event);
        $level = $this->levels[$index] ?? 0;
        $code = $this->participant->code;
        if ($event instanceof SequenceMessage || $event instanceof SequenceExo) {
            $activated = false;
            $deactivated = false;
            for ($k = $this->nextLife[$index + 1]; -1 !== $k; $k = $this->nextLife[$k + 1]) {
                $life = $this->events->events[$k];
                \assert($life instanceof SequenceLife, description: 'the chain holds changes of life only');
                $message = $this->events->messageOf($life);
                if (null === $message || !$this->events->parallelWith($message, $event) || $life->participant !== $code || !TeozEvents::involves($event, $code)) {
                    continue;
                }
                if (History::IgnoreFutureActivate !== $mode && 'activate' === $life->type) {
                    $activated = true;
                    if ($deactivated) {
                        break;
                    }
                    ++$level;
                }
                if (History::ConsiderFutureDeactivate === $mode && \in_array($life->type, ['deactivate', 'destroy'], strict: true)) {
                    $deactivated = true;
                    if ($activated) {
                        break;
                    }
                    $level = \max(0, $level - 1);
                }
            }
        }
        if ($event instanceof SequenceNote) {
            foreach (\array_slice($this->events->events, $index + 1) as $next) {
                if ($next instanceof SequenceNote) {
                    continue;
                }
                if (!$next instanceof SequenceLife) {
                    break;
                }
                if ($next->participant !== $code || null === $this->events->messageOf($next)) {
                    continue;
                }
                $level += History::IgnoreFutureActivate !== $mode && 'activate' === $next->type ? 1 : 0;
                $level = History::ConsiderFutureDeactivate === $mode && \in_array($next->type, ['deactivate', 'destroy'], strict: true) ? \max(0, $level - 1) : $level;
            }
        }

        return \max(0, $level);
    }

    /** The deepest the participant is ever active. */
    public function maxLevel(): int
    {
        $max = 0;
        $level = 0;
        foreach ($this->events->events as $event) {
            if (!$event instanceof SequenceLife || $event->participant !== $this->participant->code) {
                continue;
            }
            $level += 'activate' === $event->type ? 1 : 0;
            $max = \max($max, $level);
            $level -= \in_array($event->type, ['deactivate', 'destroy'], strict: true) ? 1 : 0;
        }

        return $max;
    }

    /**
     * The activation boxes, level by level, and the crosses where the participant is
     * destroyed, about its lifeline at a point across, from where it is created down to the end.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $top, float $createY, float $height): array
    {
        $stairs = $this->stairs($createY, $height);
        $marks = [];
        $deepest = 0;
        foreach ($stairs as $step) {
            $deepest = \max($deepest, $step->indent);
        }
        for ($level = 1; $level <= $deepest; ++$level) {
            \array_push($marks, ...$this->level($x + ((($level - 1) * ActiveLine::WIDTH) / 2), $top, $level, $stairs));
        }
        foreach ($stairs as $step) {
            if (0 !== $step->indent) {
                continue;
            }
            \array_push($marks, ...$this->cross($x, $top, $step));
        }

        return $marks;
    }

    /**
     * Where the activation changes down the lifeline: at each event that touches it, how deep
     * it is, whether a destruction follows, and the colour an activation takes.
     *
     * @return list<Step>
     */
    private function stairs(float $createY, float $height): array
    {
        $stairs = [];
        $indent = 0;
        $lastMessage = null;
        $position = null;
        $activated = false;
        $deactivated = false;
        foreach ($this->events->events as $event) {
            if ($event instanceof SequenceNote) {
                $lastMessage = null;
                $activated = false;
                $deactivated = false;
            }
            $potential = $this->steps[\spl_object_id($event)] ?? null;
            // A change of life after a message stays where the message touches, unless it is attached to another.
            $afterMessage = null !== $position && null !== $lastMessage && $event instanceof SequenceLife;
            if (!$afterMessage) {
                $position = $potential;
            }
            if ($afterMessage && $event instanceof SequenceLife) {
                if ($event->participant !== $this->participant->code) {
                    continue;
                }
                $message = $this->events->messageOf($event);
                $moved = null === $message || !$this->events->parallelWith($message, $lastMessage) || ('activate' === $event->type && $deactivated) || ('deactivate' === $event->type && $activated);
                $position = $moved ? $potential : $position;
                $activated = $activated || 'activate' === $event->type;
                $deactivated = $deactivated || 'deactivate' === $event->type;
            }
            if ($event instanceof SequenceMessage || $event instanceof SequenceExo) {
                if ($this->events->parallelWith($event, $lastMessage)) {
                    continue;
                }
                $activated = false;
                $deactivated = false;
                $lastMessage = $event;
            }
            if (null === $position) {
                continue;
            }
            $indent = $this->levelAt($event, History::ConsiderFutureDeactivate);
            $stairs = self::add($stairs, new Step(\max($createY, $position), $this->destroyedAfter($event), $indent, $this->color($event)));
        }

        return self::add($stairs, new Step($height, destroy: false, indent: $indent));
    }

    /**
     * The steps with one more after them, unless it stands above the last.
     *
     * @param list<Step> $stairs
     * @return list<Step>
     */
    private static function add(array $stairs, Step $step): array
    {
        $last = \end($stairs);
        if (false === $last || $step->y >= $last->y) {
            $stairs[] = $step;
        }

        return $stairs;
    }

    /**
     * One level's boxes: each from where it reaches the level to where it falls back, cut by delays.
     *
     * @param list<Step> $stairs
     * @return list<Mark>
     */
    private function level(float $x, float $top, int $level, array $stairs): array
    {
        $marks = [];
        $start = null;
        $last = \count($stairs) - 1;
        foreach ($stairs as $index => $step) {
            if (null === $start && $step->indent === $level) {
                $start = $step;
                continue;
            }
            if (null === $start || ($index !== $last && $step->indent >= $level)) {
                continue;
            }
            \array_push($marks, ...$this->box($x, $top, $start, $step->y));
            if ($step->indent > 0) {
                \array_push($marks, ...$this->cross($x, $top, $step));
            }
            $start = null;
        }

        return $marks;
    }

    /** @return list<Mark> an activation box from a step down to a point, cut where delays stand */
    private function box(float $x, float $top, Step $start, float $end): array
    {
        $segments = $this->cut($start->y, $end);
        $marks = [];
        $count = \count($segments);
        foreach ($segments as $index => [$from, $to]) {
            $box = $this->rose->activation($this->participant, $start->colors, 0 === $index, $index === ($count - 1));
            \array_push($marks, ...$box->marks($x - (ActiveLine::WIDTH / 2), $top + $from, new Area(ActiveLine::WIDTH, $to - $from), Layer::Foreground));
        }

        return $marks;
    }

    /** @return list<Mark> the cross where the participant is destroyed at a step, if it is */
    private function cross(float $x, float $top, Step $step): array
    {
        if (!$step->destroy) {
            return [];
        }
        $cross = $this->rose->destroy();
        [$width, $height] = $cross->size();

        return $cross->marks($x - ($width / 2), $top + $step->y - ($height / 2), new Area($width, $height), Layer::Foreground);
    }

    /**
     * A stretch down the lifeline without the delays that stand inside it.
     *
     * @return list<array{float, float}>
     */
    private function cut(float $from, float $to): array
    {
        $delays = $this->delays;
        \usort($delays, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $segments = [];
        $pending = $from;
        foreach ($delays as [$y, $height]) {
            if (\abs($y - $pending) < 0.001) {
                $pending = $y + $height;
                continue;
            }
            if ($y < $pending) {
                continue;
            }
            if ($y > $to) {
                break;
            }
            if (($y + $height) <= $to) {
                $segments[] = [$pending, $y];
                $pending = $y + $height;
            }
        }
        if ($pending < $to) {
            $segments[] = [$pending, $to];
        }

        return $segments;
    }

    /** Whether a message is followed, notes aside, by the participant's destruction among its changes of life. */
    private function destroyedAfter(SequenceEvent $event): bool
    {
        if (!$event instanceof SequenceMessage) {
            return false;
        }
        foreach (\array_slice($this->events->events, $this->events->place($event) + 1) as $next) {
            if ($next instanceof SequenceNote) {
                continue;
            }
            if (!$next instanceof SequenceLife) {
                return false;
            }
            if ('destroy' === $next->type && $next->participant === $this->participant->code) {
                return true;
            }
        }

        return false;
    }

    /** @return array{?string, ?string} the background and line colours of the activation an event starts */
    private function color(SequenceEvent $event): array
    {
        if ($event instanceof SequenceLife && 'activate' === $event->type) {
            return [$event->color, $event->line];
        }
        if (!$event instanceof SequenceMessage && !$event instanceof SequenceExo) {
            return [null, null];
        }
        foreach (\array_slice($this->events->events, $this->events->place($event) + 1) as $next) {
            if ($next instanceof SequenceNote) {
                continue;
            }
            if (!$next instanceof SequenceLife || $this->events->messageOf($next) !== $event) {
                return [null, null];
            }
            if ('activate' === $next->type && $next->participant === $this->participant->code) {
                return [$next->color, $next->line];
            }
        }

        return [null, null];
    }

    /** Counts each event's level once, as the events come. */
    private function cache(): void
    {
        if (null !== $this->levels) {
            return;
        }
        $events = $this->events->events;
        $level = 0;
        $levels = [];
        foreach ($events as $event) {
            if ($event instanceof SequenceLife && $event->participant === $this->participant->code) {
                $level += 'activate' === $event->type ? 1 : 0;
                $level = \in_array($event->type, ['deactivate', 'destroy'], strict: true) ? \max(0, $level - 1) : $level;
            }
            $levels[] = $level;
        }
        $this->levels = $levels;
        $next = \array_fill(0, \count($events) + 1, -1);
        for ($index = \count($events) - 1; $index >= 0; --$index) {
            $event = $events[$index];
            $next[$index] = match (true) {
                $event instanceof SequenceLife => $index,
                $event instanceof SequenceNote, $event instanceof SequenceMessage, $event instanceof SequenceExo => $next[$index + 1],
                default => -1,
            };
        }
        $this->nextLife = $next;
    }
}
