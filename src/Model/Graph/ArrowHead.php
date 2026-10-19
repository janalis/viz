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
    /** A hollow triangle: generalization, realization. */
    case Triangle = 'triangle';
    /** A filled diamond: composition. */
    case Diamond = 'diamond';
    /** A hollow diamond: aggregation. */
    case HollowDiamond = 'hollow-diamond';
}
