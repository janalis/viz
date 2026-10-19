<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A divider (`== text ==`), a delay (`...` or `... text ...`), space (`|||` or `||n||`), or a reference over participants. */
final readonly class SequenceMark implements SequenceEvent
{
    /**
     * @param list<string> $text
     * @param list<string> $participants for a reference
     */
    public function __construct(
        /** `divider`, `delay`, `space` or `reference` */
        public string $kind,
        public array $text = [],
        public array $participants = [],
        /** Space's height when given. */
        public ?int $size = null,
        public ?string $color = null,
    ) {}
}
