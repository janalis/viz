<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/** What PlantUML's `NTetris` places, as its `Staged`: the stages it spans, top to bottom, and how many columns it takes. */
interface NwStaged
{
    public function start(): int;

    public function end(): int;

    public function width(): int;
}
