<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A participant created by the next message, or destroyed by it. */
final readonly class Lifecycle implements Step
{
    public function __construct(
        public string $participant,
        public bool $create = true,
    ) {}
}
