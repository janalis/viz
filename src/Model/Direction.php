<?php

declare(strict_types=1);

namespace Janalis\Viz\Model;

/** Which way ranks advance: the first node at the start of the flow, edges pointing along it. */
enum Direction: string
{
    case TopBottom = 'TB';
    case BottomTop = 'BT';
    case LeftRight = 'LR';
    case RightLeft = 'RL';
}
