<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Arrows between tiles of swimlanes, each drawn in the swimlane both stand in, or bent across as PlantUML bends it. */
final readonly class Links implements Connection
{
    /** @param list<array{Snake, ?string, ?string, \Closure(float, float): list<Snake|Shape>}> $links each arrow, the swimlanes it leaves and reaches, and its route across */
    public function __construct(
        private array $links,
    ) {}

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->links as [$snake, $from, $to, $across]) {
            $canvas->link($snake, $from, $to, $across);
        }
    }
}
