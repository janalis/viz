<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/** A change in a participant's life: `activate`, `deactivate`, `destroy` or `create`. */
final class SequenceLife implements SequenceEvent
{
    public function __construct(
        public readonly string $participant,
        public readonly string $type,
        public readonly ?string $color = null,
        public readonly ?string $line = null,
    ) {}
}
