<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/**
 * Native work is a generator that yields between small steps, so a host with an
 * event loop can slice it into its frames. A caller with no loop runs it here,
 * start to finish.
 */
final class Work
{
    /**
     * @template T
     * @param \Generator<int, null, mixed, T> $work
     * @return T
     */
    public static function run(\Generator $work): mixed
    {
        while ($work->valid()) {
            $work->next();
        }

        return $work->getReturn();
    }
}
