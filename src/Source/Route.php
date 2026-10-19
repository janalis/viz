<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

/** How a diagram reaches a language: in its own family, lowered to a graph, or not at all. */
enum Route: string
{
    case Direct = 'direct';
    case ViaGraph = 'via graph';
    case Unsupported = 'unsupported';
}
