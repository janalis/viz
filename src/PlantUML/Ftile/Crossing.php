<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** The arrow from one step down to the next, which bends across when they stand in different swimlanes. */
final readonly class Crossing implements Connection
{
    public function __construct(
        private Snake $snake,
        private Ftile $from,
        private Ftile $to,
    ) {}

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $canvas->crossing($this->snake, FtileLane::out($this->from), FtileLane::in($this->to));
    }
}
