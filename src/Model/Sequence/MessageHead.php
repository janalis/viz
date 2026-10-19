<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** The mark at a message's receiving end. */
enum MessageHead: string
{
    /** A plain line, no head. */
    case None = 'none';
    /** A filled arrowhead: a synchronous call. */
    case Filled = 'filled';
    /** An open arrowhead: an asynchronous message. */
    case Open = 'open';
    /** A cross: a lost or destroying message. */
    case Cross = 'cross';
}
