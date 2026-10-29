<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** Which of a polygon's outlines' insides a renderer fills where they cross or nest. */
enum FillRule
{
    /** What an odd count of outlines encloses, so that one inside another is a hole. */
    case EvenOdd;
    /** What the outlines wind round, as SVG fills a path unless told otherwise. */
    case NonZero;
}
