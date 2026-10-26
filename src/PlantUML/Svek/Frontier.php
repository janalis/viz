<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Svek;

use Janalis\Viz\Model\Direction;

/**
 * The frame of a cluster with points on its border, as PlantUML's frontier calculator finds
 * it: around what the cluster holds and through the points; the cluster's own sides where no
 * point touches them; 18 further out beside a point that near a corner of the side it stands
 * on, unless a point stands in that corner along the way the ranks run; wide enough for its
 * title. Each box is its left, top, right and bottom; its sides numbered so.
 */
final class Frontier
{
    /** How near a corner a point pushes the side beside it out, and how far: three of its radii. */
    private const float DELTA = 18.0;

    /** @var array{float, float, float, float} */
    private array $core;

    /**
     * @param array{float, float, float, float} $initial the cluster's box
     * @param list<array{float, float}> $points
     */
    private function __construct(
        private readonly array $initial,
        private readonly array $points,
    ) {
        [$x, $y] = [($initial[0] + $initial[2]) / 2, ($initial[1] + $initial[3]) / 2];
        $this->core = [$x - 1, $y - 1, $x + 1, $y + 1];
    }

    /**
     * @param array{float, float, float, float} $initial the cluster's box
     * @param list<array{float, float, float, float}> $insides the boxes of what it holds
     * @param list<array{float, float}> $points the centres of the points on its border
     * @param float $title how wide its title is; nought for none
     * @return array{float, float, float, float}
     */
    public static function of(array $initial, array $insides, array $points, Direction $direction, float $title): array
    {
        $frontier = new self($initial, $points);
        $frontier->core = [] === $insides ? $frontier->core : self::merged($insides);
        $frontier->core = self::merged([$frontier->core, ...\array_map(static fn (array $point): array => [$point[0], $point[1], $point[0], $point[1]], $points)]);
        $frontier->touch();
        $frontier->push(Direction::LeftRight === $direction ? 0 : 1);

        return $title > 0 ? $frontier->widened($title + 10) : $frontier->core;
    }

    /**
     * @param non-empty-list<array{float, float, float, float}> $boxes
     * @return array{float, float, float, float}
     */
    private static function merged(array $boxes): array
    {
        return [\min(\array_column($boxes, 0)), \min(\array_column($boxes, 1)), \max(\array_column($boxes, 2)), \max(\array_column($boxes, 3))];
    }

    /** Takes the cluster's own side where no point touches it. */
    private function touch(): void
    {
        foreach ([0, 1, 2, 3] as $side) {
            $touched = \array_filter($this->points, fn (array $point): bool => $point[$side % 2] === $this->core[$side]);
            $this->core[$side] = [] === $touched ? $this->initial[$side] : $this->core[$side];
        }
    }

    /** Pushes out each side a point on a side across it stands near, but for one whose corner a point stands in along the ranks' axis. */
    private function push(int $axis): void
    {
        $push = [false, false, false, false];
        foreach ($this->points as $point) {
            foreach ([0, 1] as $along) {
                if (!$this->onSide($point, 1 - $along)) {
                    continue;
                }
                $push[$along] = $push[$along] || \abs($point[$along] - $this->core[$along]) < self::DELTA;
                $push[$along + 2] = $push[$along + 2] || \abs($point[$along] - $this->core[$along + 2]) < self::DELTA;
            }
        }
        foreach ($this->points as $point) {
            foreach ([$axis, $axis + 2] as $side) {
                $push[$side] = $push[$side] && !($point[$axis] === $this->core[$side] && $this->onSide($point, 1 - $axis));
            }
        }
        foreach ([0, 1, 2, 3] as $side) {
            $outward = $side < 2 ? -self::DELTA : self::DELTA;
            $this->core[$side] += $push[$side] ? $outward : 0.0;
        }
    }

    /**
     * Whether a point stands on either side across an axis: 0 the left or right, 1 the top or bottom.
     *
     * @param array{float, float} $point
     */
    private function onSide(array $point, int $axis): bool
    {
        return $point[$axis] === $this->core[$axis] || $point[$axis] === $this->core[$axis + 2];
    }

    /**
     * The frame no narrower than a width, widened evenly but never left of the cluster.
     *
     * @return array{float, float, float, float}
     */
    private function widened(float $width): array
    {
        [$left, $top, $right, $bottom] = $this->core;
        $short = $right - $left - $width;
        if ($short >= 0) {
            return $this->core;
        }
        $left = \max($this->initial[0], $left + ($short / 2));

        return [$left, $top, $left + $width, $bottom];
    }
}
