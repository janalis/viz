<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** An if, its else-ifs and its else; with no else, the flow skips past it when no guard holds. */
final readonly class Decision implements Step
{
    /** @param non-empty-list<Branch> $branches */
    public function __construct(
        public array $branches,
        public ?Branch $otherwise = null,
    ) {}
}
