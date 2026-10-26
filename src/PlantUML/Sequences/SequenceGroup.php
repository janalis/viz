<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** The start of a group (`alt`, `loop`, `group` and the rest), a section of it (`else`), or its `end`. */
final class SequenceGroup implements SequenceEvent
{
    /** @var list<SequenceNote> the notes on its end, left or right of its frame */
    public array $notes = [];

    public function __construct(
        /** `start`, `else` or `end` */
        public readonly string $role,
        /** `alt`, `opt`, `loop`, `par`, `break`, `critical`, `group`, or a group's own, for a start */
        public readonly string $type = '',
        public readonly string $comment = '',
        public readonly ?string $color = null,
        public readonly ?string $background = null,
        public readonly bool $parallel = false,
    ) {}
}
