<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** One action. */
final readonly class Action implements Step
{
    public function __construct(
        public string $label,
    ) {}
}
