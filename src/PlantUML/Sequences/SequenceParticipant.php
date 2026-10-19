<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A participant as a PlantUML sequence diagram declares it, or as a message first names it. */
final class SequenceParticipant
{
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $color = null;
    /** How many times it is active from the start, by `activate` before any message. */
    public int $initialLife = 0;

    /** @param list<string> $display */
    public function __construct(
        public readonly string $code,
        public array $display,
        /** `participant`, `actor`, `boundary`, `control`, `entity`, `database`, `collections` or `queue` */
        public string $type = 'participant',
        public readonly int $order = 0,
        /** The box around it, by its index among the diagram's boxes, if any. */
        public readonly ?int $box = null,
    ) {}
}
