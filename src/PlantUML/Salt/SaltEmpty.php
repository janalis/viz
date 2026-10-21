<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementEmpty`: a side of a border left out, 1 by 1, drawing nothing. */
final readonly class SaltEmpty implements SaltElement
{
    #[\Override]
    public function size(): array
    {
        return [1.0, 1.0];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        return [];
    }
}
