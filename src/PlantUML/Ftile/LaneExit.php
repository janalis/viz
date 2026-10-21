<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** A tile the flow leaves in a swimlane other than its own, as PlantUML finds it. */
interface LaneExit
{
    /** The swimlane the flow leaves it in, or null for its own. */
    public function exitLane(): ?string;
}
