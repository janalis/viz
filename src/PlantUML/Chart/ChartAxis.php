<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

/**
 * A chart's numeric axis, as PlantUML's `ChartAxis`: 0 to 100 until a range is given, and
 * stretched by each value until then; its ticks where they are named, else every spacing,
 * else six evenly.
 */
final class ChartAxis
{
    public string $title = '';
    public float $min = 0.0;
    public float $max = 100.0;
    public bool $auto = true;
    /** @var list<array{float, string}> each named tick's value and label, in the order first named */
    public array $ticks = [];
    public ?float $spacing = null;
    /** Whether the title stands across above the axis rather than along it. */
    public bool $top = false;

    public function range(?string $title, ?float $min, ?float $max): void
    {
        if (null !== $title) {
            $this->title = $title;
        }
        if (null !== $min) {
            [$this->min, $this->auto] = [$min, false];
        }
        if (null !== $max) {
            [$this->max, $this->auto] = [$max, false];
        }
    }

    public function include(float $value): void
    {
        if (!$this->auto) {
            return;
        }
        $this->min = \min($this->min, $value);
        $this->max = \max($this->max, $value);
    }

    /** How far up a value stands, from 0 at the axis' minimum to 1 at its maximum. */
    public function ratio(float $value): float
    {
        return ($value - $this->min) / ($this->max - $this->min);
    }

    /** As `valueToPixel`: a value's place between two ends, the first for a flat axis. */
    public function pixel(float $value, float $from, float $to): float
    {
        return $this->max === $this->min ? $from : $from + ($this->ratio($value) * ($to - $from));
    }
}
