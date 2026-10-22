<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/** Room across, as `<space:…>` makes it, as PlantUML's `AtomSpace`. */
final readonly class CreoleSpace implements CreoleAtom
{
    public function __construct(
        private float $width,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->width, 1.0];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        return [];
    }
}
