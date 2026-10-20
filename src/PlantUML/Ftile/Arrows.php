<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Arrows set where they go once and for all, drawn with the tile they stand by. */
final readonly class Arrows implements Connection
{
    /** @param list<Snake> $snakes */
    public function __construct(
        private array $snakes,
    ) {}

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->snakes as $snake) {
            $canvas->snake($snake);
        }
    }
}
