<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

/** How a set of nodes is tied to a rank, as DOT's `rank` attribute says. */
enum RankKind: string
{
    /** On one rank together. */
    case Same = 'same';
    /** On the first rank. */
    case Min = 'min';
    /** On the first rank, which holds nothing else. */
    case Source = 'source';
    /** On the last rank. */
    case Max = 'max';
    /** On the last rank, which holds nothing else. */
    case Sink = 'sink';
}
