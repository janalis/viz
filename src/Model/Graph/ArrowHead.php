<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

/** How one end of an edge is drawn. */
enum ArrowHead: string
{
    case None = 'none';
    case Arrow = 'arrow';
    case Circle = 'circle';
    case Cross = 'cross';
}
