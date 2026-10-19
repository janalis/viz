<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

/** The node outlines all three languages can draw, each by its own name. */
enum Shape: string
{
    case Box = 'box';
    case Rounded = 'rounded';
    case Stadium = 'stadium';
    case Circle = 'circle';
    case Ellipse = 'ellipse';
    case Diamond = 'diamond';
    case Hexagon = 'hexagon';
    case Parallelogram = 'parallelogram';
    case Cylinder = 'cylinder';
    case Note = 'note';
}
