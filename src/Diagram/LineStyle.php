<?php

declare(strict_types=1);

namespace Janalis\Viz\Diagram;

/** How an edge's line is drawn. */
enum LineStyle: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Bold = 'bold';
}
