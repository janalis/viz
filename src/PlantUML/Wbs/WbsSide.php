<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

/** Which side of its parent an element stands on, as `-` or `<` puts it on the left. */
enum WbsSide
{
    case Left;
    case Right;
}
