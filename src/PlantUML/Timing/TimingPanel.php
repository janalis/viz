<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;

/**
 * A player's panels, as PlantUML's `Panels` are: what it writes left of the time axis, as
 * wide as it needs, and what it draws along it, as tall as it needs; where a moment of it
 * stands, for the arrows of messages — one point, or two, top and bottom, between changes.
 */
interface TimingPanel
{
    public function leftWidth(): float;

    public function height(): float;

    /** @return list<Mark> its left panel, its top left at a point, right-aligned within a width */
    public function left(float $x, float $y, float $width): array;

    /** @return list<Mark> its right panel, the time axis's start at a point */
    public function right(float $x, float $y): array;

    /** @return ?array{array{float, float}, array{float, float}} from the time axis's start at the panel's top */
    public function projection(TimingTick $tick): ?array;
}
