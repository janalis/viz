<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** A note beside the step before it. */
final readonly class Note implements Step
{
    public function __construct(
        public string $text,
        public bool $left = false,
    ) {}
}
