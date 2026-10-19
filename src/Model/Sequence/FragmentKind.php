<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** The operator of a combined fragment; a highlight is an unlabelled colored band. */
enum FragmentKind: string
{
    case Loop = 'loop';
    case Alt = 'alt';
    case Opt = 'opt';
    case Par = 'par';
    case Critical = 'critical';
    case Break = 'break';
    case Group = 'group';
    case Highlight = 'highlight';
}
