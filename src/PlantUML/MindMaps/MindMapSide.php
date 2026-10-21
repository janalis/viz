<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

/** Which side of its root an idea stands on: the right — below from top to bottom — or the left — above. */
enum MindMapSide
{
    case Right;
    case Left;
}
