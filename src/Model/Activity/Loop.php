<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** A while loop, which tests before its body, or a repeat loop, which tests after. */
final readonly class Loop implements Step
{
    /** @param list<Step> $steps */
    public function __construct(
        public string $condition,
        public array $steps = [],
        public bool $repeat = false,
        /** The label while the loop goes on. */
        public string $label = '',
        /** The label on the way out. */
        public string $exit = '',
    ) {}
}
