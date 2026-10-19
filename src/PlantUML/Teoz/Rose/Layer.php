<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** Teoz draws a diagram twice: backgrounds first, behind the lifelines, then everything else in front. */
enum Layer
{
    case Background;
    case Foreground;
}
