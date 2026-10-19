<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** A stretch of a participant's lifeline, or of its dotted delay, down the middle of its area. */
final readonly class LifeLine implements Component
{
    public function __construct(
        private Pen $pen,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [1.0, 20.0];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        $middle = $x + (int) ($area->width / 2);

        return [$this->pen->line($middle, $y, $middle, $y + $area->height)];
    }
}
