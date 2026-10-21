<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

/** How an element of a work breakdown structure is drawn, as PlantUML's `IdeaShape`: in a box, as its text alone (`_`), or not at all (`_` without a label). */
enum WbsShape
{
    case Box;
    case None;
    case Pseudo;
}
