<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** The mark at one end of a relation. */
enum RelationEnd: string
{
    case None = 'none';
    /** Association or dependency. */
    case Arrow = 'arrow';
    /** Generalization or realization. */
    case Triangle = 'triangle';
    /** Composition. */
    case Diamond = 'diamond';
    /** Aggregation. */
    case HollowDiamond = 'hollow-diamond';
    /** A provided interface. */
    case Lollipop = 'lollipop';
    case Cross = 'cross';
}
