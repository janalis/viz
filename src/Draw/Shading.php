<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** How a polygon's gradient runs: across from its left edge to its right, or out from its centre. */
enum Shading
{
    /** From the polygon's left edge to its right. */
    case Linear;
    /** From the centre of the polygon's box out, its last colour reached three quarters of the box's width and height away. */
    case Radial;
}
