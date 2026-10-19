<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;

/** An activity: its steps in order, branching, looping and forking as a structured flow. */
final readonly class ActivityDiagram implements Diagram
{
    /** @param list<Step> $steps */
    public function __construct(
        public array $steps = [],
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::Activity;
    }

    #[\Override]
    public function graph(): Graph
    {
        return ActivityLowering::graph($this);
    }
}
