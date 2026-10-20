<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

/** A note beside an activity diagram's step: on its left or its right, linked to it or floating. */
final readonly class PositionedNote
{
    /** @param list<string> $text */
    public function __construct(
        public array $text,
        /** `left` or `right` */
        public string $position = 'left',
        public bool $floating = false,
        public ?string $color = null,
    ) {}
}
