<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Room of a size, nothing drawn. */
final readonly class EmptyBlock implements TextBlock
{
    public function __construct(
        private float $width = 0.0,
        private float $height = 0.0,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->width, $this->height];
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        return [];
    }
}
