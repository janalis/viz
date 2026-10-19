<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** The start of a group (`alt`, `loop`, `group` and the rest), a section of it (`else`), or its `end`. */
final readonly class SequenceGroup implements SequenceEvent
{
    public function __construct(
        /** `start`, `else` or `end` */
        public string $role,
        /** `alt`, `opt`, `loop`, `par`, `break`, `critical`, `group`, or a group's own, for a start */
        public string $type = '',
        public string $comment = '',
        public ?string $color = null,
        public ?string $background = null,
        public bool $parallel = false,
    ) {}
}
