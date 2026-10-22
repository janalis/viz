<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;

/** A list's bullet, as PlantUML's `Bullet`: a disc for the first level, a small square 8 further in for each deeper one. */
final readonly class CreoleBullet implements CreoleAtom
{
    public function __construct(
        private int $order,
        private string $color,
    ) {}

    #[\Override]
    public function size(): array
    {
        return 0 === $this->order ? [12.0, 5.0] : [8.0 + (8 * $this->order), 3.0];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0 === $this->order ? -5.0 : -7.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        if (0 === $this->order) {
            $disc = DescriptionBox::ellipse($x + 3, $y, 5.0, 5.0, 'none', $this->color, 0.0);

            return [new Mark(MarkKind::Polygon, $disc->points, stroke: 'none', fill: $this->color, strokeWidth: 0.0)];
        }

        return [ClassBox::rectangle($x + 1 + (8 * $this->order), $y, 3.5, 3.5, 'none', $this->color, 0.0, 0.0)];
    }
}
