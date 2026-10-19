<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

/**
 * A state as a PlantUML state diagram declares it — or a pseudostate: `start` and `end` for
 * a scope's `[*]`, `choice`, `fork`, `join`, `history` and `deephistory` — in the composite
 * state and the region that hold it.
 */
final class StateNode
{
    /** @var list<string> the lines written after `State :` */
    public array $description = [];
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $color = null;

    /** @param list<string> $display */
    public function __construct(
        public readonly string $id,
        public array $display,
        public string $kind = 'state',
        public readonly ?string $group = null,
        public readonly int $region = 0,
    ) {}
}
