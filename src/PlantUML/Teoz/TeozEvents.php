<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;

/**
 * A sequence diagram's events in time, as teoz reads them: which message each change of life
 * is attached to, and which message a parallel one goes along with.
 */
final class TeozEvents
{
    /** @var array<int, SequenceMessage|SequenceExo> each attached change of life's message, by the change's object id */
    private array $messages = [];
    /** @var array<int, SequenceMessage|SequenceExo> each parallel message's elder, by its object id */
    private array $brothers = [];
    /** @var array<int, int> each event's place in time, by its object id */
    private array $places = [];

    /** @param list<SequenceEvent> $events */
    public function __construct(
        public readonly array $events,
    ) {
        $last = null;
        foreach ($events as $index => $event) {
            $this->places[\spl_object_id($event)] ??= $index;
            if (!$event instanceof SequenceMessage && !$event instanceof SequenceExo) {
                continue;
            }
            foreach ($event->lives as $life) {
                $this->messages[\spl_object_id($life)] = $event;
            }
            if ($event->parallel && ($last instanceof SequenceMessage || $last instanceof SequenceExo)) {
                $this->brothers[\spl_object_id($event)] = $last;
            }
            $last = $event;
        }
    }

    /** The message a change of life is attached to, if any. */
    public function messageOf(SequenceLife $life): SequenceMessage|SequenceExo|null
    {
        return $this->messages[\spl_object_id($life)] ?? null;
    }

    /** Whether a message is another, or goes along with it, written `&` after it. */
    public function parallelWith(SequenceMessage|SequenceExo $message, SequenceMessage|SequenceExo|null $other): bool
    {
        for ($current = $message; null !== $current; $current = $this->brothers[\spl_object_id($current)] ?? null) {
            if ($current === $other) {
                return true;
            }
        }

        return false;
    }

    /** An event's place in time. */
    public function place(SequenceEvent $event): int
    {
        return $this->places[\spl_object_id($event)];
    }

    /**
     * The changes of life a message carries.
     *
     * @return list<string> `activate`, `deactivate`, `destroy` or `create`
     */
    public static function lives(SequenceMessage|SequenceExo $message): array
    {
        return \array_map(static fn (SequenceLife $life): string => $life->type, $message->lives);
    }

    /** Whether a message involves a participant, by its code. */
    public static function involves(SequenceMessage|SequenceExo $message, string $participant): bool
    {
        return $message instanceof SequenceMessage ? $message->from === $participant || $message->to === $participant : $message->participant === $participant;
    }

    /** Whether an event is written `&`, to go along with the one before. */
    public static function parallel(SequenceEvent $event): bool
    {
        return ($event instanceof SequenceMessage || $event instanceof SequenceExo || $event instanceof SequenceGroup || $event instanceof SequenceNote) && $event->parallel;
    }
}
