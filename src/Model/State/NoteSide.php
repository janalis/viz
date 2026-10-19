<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/** The side of its state a note stands on. */
enum NoteSide: string
{
    case Left = 'left';
    case Right = 'right';
}
