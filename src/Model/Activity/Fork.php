<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** Flows that run in parallel and join again; a split's flows simply start together. */
final readonly class Fork implements Step
{
    /** @param non-empty-list<list<Step>> $flows */
    public function __construct(
        public array $flows,
        public bool $split = false,
    ) {}
}
