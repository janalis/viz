<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** The neighbouring rank a sweep looks at: the one above, or the one below. */
enum Side: string
{
    case Above = 'above';
    case Below = 'below';

    public function other(): self
    {
        return self::Above === $this ? self::Below : self::Above;
    }
}
