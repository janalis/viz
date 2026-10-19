<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A message from or to the edge of the diagram: `[->`, `->]` and their kin. */
final class SequenceExo implements SequenceEvent
{
    /** @var list<SequenceLife> */
    public array $lives = [];
    /** @var list<SequenceNote> */
    public array $notes = [];
    public bool $parallel = false;

    /** @param list<string> $text */
    public function __construct(
        public readonly string $participant,
        /** `from_left`, `to_left`, `from_right` or `to_right` */
        public readonly string $type,
        public readonly array $text,
        public readonly Arrow $arrow,
        public readonly ?string $number = null,
        /** Whether the arrow stops short of the edge, written with `?`. */
        public readonly bool $short = false,
    ) {}
}
