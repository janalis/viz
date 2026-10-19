<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

/** Where a line of a DOT label sits: `\n` centres it, `\l` sets it left, `\r` right. */
enum Justify: string
{
    case Center = 'center';
    case Left = 'left';
    case Right = 'right';
}
