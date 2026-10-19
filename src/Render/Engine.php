<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** Which renderers draw a language: the native one with the tool as fallback, or either alone. */
enum Engine: string
{
    case Auto = 'auto';
    case Native = 'native';
    case Cli = 'cli';
}
