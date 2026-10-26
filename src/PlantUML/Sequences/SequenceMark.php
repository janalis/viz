<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A divider (`== text ==`), a delay (`...` or `... text ...`), space (`|||` or `||n||`), or a reference over participants. */
final class SequenceMark implements SequenceEvent
{
    /** @var list<SequenceNote> a reference's notes, left or right of it */
    public array $notes = [];

    /**
     * @param list<string> $text
     * @param list<string> $participants for a reference
     */
    public function __construct(
        /** `divider`, `delay`, `space` or `reference` */
        public readonly string $kind,
        public readonly array $text = [],
        public readonly array $participants = [],
        /** Space's height when given. */
        public readonly ?int $size = null,
        public readonly ?string $color = null,
    ) {}
}
