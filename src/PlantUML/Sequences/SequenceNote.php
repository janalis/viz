<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A note over, left or right of one or two participants, across all of them, or on a message. */
final readonly class SequenceNote implements SequenceEvent
{
    /**
     * @param list<string> $participants by code: none across the diagram, two for `over A, B`
     * @param list<string> $text
     */
    public function __construct(
        public array $participants,
        /** `left`, `right`, `over`, `across`, and on a message `top` and `bottom` too */
        public string $position,
        public array $text,
        /** `note`, `hnote` (a hexagon) or `rnote` (a rectangle) */
        public string $style = 'note',
        public ?string $color = null,
    ) {}
}
