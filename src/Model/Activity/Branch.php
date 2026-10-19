<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** One way through a decision: its guard and the label on its first arrow, then its steps. */
final readonly class Branch
{
    /** @param list<Step> $steps */
    public function __construct(
        public string $condition = '',
        public string $label = '',
        public array $steps = [],
    ) {}
}
