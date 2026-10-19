<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/** The substates of a composite state, or one of its concurrent regions. */
final readonly class Region
{
    /** @param list<State> $states */
    public function __construct(
        public array $states = [],
    ) {}
}
