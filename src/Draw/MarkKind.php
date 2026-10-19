<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** What a mark draws: a closed and filled outline, an open line, a line of text or a raster picture. */
enum MarkKind: string
{
    case Polygon = 'polygon';
    case Polyline = 'polyline';
    case Text = 'text';
    case Image = 'image';
}
