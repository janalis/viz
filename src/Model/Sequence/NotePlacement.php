<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** Where a note stands against its participants. */
enum NotePlacement: string
{
    case Left = 'left of';
    case Right = 'right of';
    case Over = 'over';
}
