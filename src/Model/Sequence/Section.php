<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** One operand of a fragment: its guard or label, and its steps. */
final readonly class Section
{
    /** @param list<Step> $steps */
    public function __construct(
        public string $label = '',
        public array $steps = [],
    ) {}
}
