<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** Notes set side by side on one row, the later written `/ note`. */
final class SequenceNotes implements SequenceEvent
{
    /** @param non-empty-list<SequenceNote> $notes */
    public function __construct(
        public array $notes,
    ) {}
}
