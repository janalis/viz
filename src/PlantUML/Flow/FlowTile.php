<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Flow;

/** A box of a flow diagram, and the cells of PlantUML's `TilesField` it stands on: two across, two down. */
final class FlowTile
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public int $xmin,
        public int $ymin,
    ) {}

    public function xmax(): int
    {
        return $this->xmin + 1;
    }

    public function ymax(): int
    {
        return $this->ymin + 1;
    }

    /** @return array{int, int} as PlantUML's `Position`'s `move`, by a side's direction */
    public static function step(string $side, int $size): array
    {
        return match ($side) {
            'n' => [0, -$size],
            's' => [0, $size],
            'w' => [-$size, 0],
            default => [$size, 0],
        };
    }

    public static function opposite(string $side): string
    {
        return ['n' => 's', 's' => 'n', 'e' => 'w', 'w' => 'e'][$side];
    }
}
