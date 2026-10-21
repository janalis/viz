<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

/** A spot of a wire diagram, as PlantUML's `Spot` keeps one: a point of a block, `0, 0` by default, and its colour, red by default. */
final readonly class WireSpot
{
    public function __construct(
        public WireBlock $block,
        public string $color = '#ff0000',
        public string $x = '0',
        public string $y = '0',
    ) {}
}
