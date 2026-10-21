<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * What PlantUML drew a shape as, which decides the room it takes: a line takes none from
 * compression, a polygon reaches 10 past its sides, a rectangle and an ellipse stop 1 short.
 */
enum ShapeKind
{
    case Rectangle;
    case Polygon;
    case Path;
    case Line;
    case Ellipse;
    case Text;
    /** Room taken, nothing drawn. */
    case Empty;
    /** Text centred across a width once compressed across, taking no room across, as a swimlane's title. */
    case Centered;
}
