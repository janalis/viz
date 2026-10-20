<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** Whether edges between ranks keep chains of their own, or merge them as dot's `concentrate` does. */
enum Concentration
{
    /** Each edge its own chain; parallel edges share one, spread apart. */
    case Apart;
    /** Edges leaving one node the same way share a trunk as far as they run together, and edges reaching one node likewise. */
    case Merged;
}
