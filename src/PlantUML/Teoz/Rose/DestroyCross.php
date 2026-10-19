<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** The cross where a participant is destroyed, 18 across. */
final readonly class DestroyCross implements Component
{
    private const float SIZE = 18.0;

    public function __construct(
        private Pen $pen,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [self::SIZE, self::SIZE];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }

        return [$this->pen->line($x, $y, $x + self::SIZE, $y + self::SIZE), $this->pen->line($x, $y + self::SIZE, $x + self::SIZE, $y)];
    }
}
