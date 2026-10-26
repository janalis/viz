<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A message between two participants, or a participant and itself. */
final class SequenceMessage implements SequenceEvent
{
    /** @var list<SequenceLife> the life events it carries: an `activate` or a `destroy` right after it */
    public array $lives = [];
    /** @var list<SequenceNote> */
    public array $notes = [];
    public bool $parallel = false;
    public ?string $anchor = null;
    /** @var list<string> the participants it is also sent to, `A -> B & C`, by code */
    public array $multicast = [];

    /** @param list<string> $text */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $text,
        public readonly Arrow $arrow,
        public readonly ?string $number = null,
    ) {}
}
