<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A participant's activation bar starting, or the innermost one ending. */
final readonly class Activation implements Step
{
    public function __construct(
        public string $participant,
        public bool $active = true,
    ) {}
}
