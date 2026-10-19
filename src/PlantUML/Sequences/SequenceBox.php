<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A `box` around participants, inside its parent box if any. */
final readonly class SequenceBox
{
    /** @param list<string> $title */
    public function __construct(
        public array $title,
        public ?string $color = null,
        public ?int $parent = null,
    ) {}
}
