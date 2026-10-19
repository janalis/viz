<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** Where the flow starts, stops, ends every flow at once, or detaches into nothing. */
final readonly class Terminal implements Step
{
    public function __construct(
        public TerminalKind $kind = TerminalKind::Start,
    ) {}
}
