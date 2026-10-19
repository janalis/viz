<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** The kind of a start or end of flow. */
enum TerminalKind: string
{
    case Start = 'start';
    /** The end of this flow. */
    case Stop = 'stop';
    /** The end of every flow. */
    case End = 'end';
    /** The flow goes on, undrawn. */
    case Detach = 'detach';
}
