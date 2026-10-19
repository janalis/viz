<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/** A note beside a state, or floating when it has no target. */
final readonly class Note
{
    public function __construct(
        public string $text,
        public ?string $target = null,
        public NoteSide $side = NoteSide::Right,
    ) {}
}
