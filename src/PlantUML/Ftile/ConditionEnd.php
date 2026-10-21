<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** How a decision closes, as `skinparam conditionEndStyle` says: on a diamond, or on a line across. */
enum ConditionEnd
{
    case Diamond;
    case Hline;
}
