<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** What a sweep or a transposition does with two neighbours that tie: keep them, or exchange them to try another order. */
enum Ties: string
{
    case Keep = 'keep';
    case Exchange = 'exchange';

    public function other(): self
    {
        return self::Keep === $this ? self::Exchange : self::Keep;
    }
}
