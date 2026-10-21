<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/**
 * PlantUML's `TimingRuler`: the moments the diagram names, each tick of the axis 50 apart — or
 * as `scale … as … pixels` asks — a tick the highest common factor of the moments, or, when
 * that is 1 and would make the diagram over 4000 wide, what keeps it to that; one tick more
 * than the moments span.
 */
final class TimingRuler
{
    /** @var array<array-key, float> by the moment written */
    private array $times = [];
    private float $interval = 50.0;
    private int $forced = 0;
    public string $format = 'decimal';

    public function add(TimingTick $tick): void
    {
        $this->times[(string) $tick->time] = $tick->time;
        if ('decimal' !== $tick->format) {
            $this->format = $tick->format;
        }
    }

    public function scale(int $tick, int $pixels): void
    {
        [$this->forced, $this->interval] = [$tick, (float) $pixels];
    }

    /** As PlantUML's `ensureNotEmpty`: 0 when nothing is named, or when the moments span it. */
    public function ensureNotEmpty(): void
    {
        if ([] === $this->times || ($this->max() > 0 && $this->min() < 0)) {
            $this->times['0'] = 0.0;
        }
    }

    /** @return list<float> in time */
    public function times(): array
    {
        $times = \array_values($this->times);
        \sort($times);

        return $times;
    }

    public function width(): float
    {
        return [] === $this->times ? 100.0 : $this->widthFor($this->unit());
    }

    public function x(float $time): float
    {
        return (($time - $this->min()) / $this->unit()) * $this->interval;
    }

    public function interval(): float
    {
        return $this->interval;
    }

    public function forced(): int
    {
        return $this->forced;
    }

    /** As PlantUML's `getNbTick`. */
    public function ticks(): int
    {
        if ([] === $this->times) {
            return 1;
        }

        return \min(1000, (int) (1 + \intdiv((int) $this->max() - (int) $this->min(), $this->unit())));
    }

    /** The first moment not before 0. */
    public function firstNotNegative(): float
    {
        foreach ($this->times() as $time) {
            if ($time >= 0) {
                return $time;
            }
        }

        return 0.0;
    }

    public function min(): float
    {
        return [] === $this->times ? 0.0 : \min($this->times);
    }

    public function max(): float
    {
        return [] === $this->times ? 0.0 : \max($this->times);
    }

    /** As PlantUML's `getOptimalTickUnit`. */
    public function unit(): int
    {
        if (0 !== $this->forced) {
            return $this->forced;
        }
        $factor = $this->factor();
        if (1 === $factor && $this->widthFor($factor) > 4000.0) {
            return (int) \round(1 + (($this->interval * ($this->max() - $this->min())) / 4000.0));
        }

        return $factor;
    }

    private function widthFor(int $unit): float
    {
        return ((($this->max() - $this->min()) / $unit) + 1) * $this->interval;
    }

    /** The highest common factor of the moments, as whole numbers, -1 when none is past 0. */
    private function factor(): int
    {
        $factor = -1;
        foreach ($this->times as $time) {
            $value = \abs((int) $time);
            if (0 === $value) {
                continue;
            }
            $factor = -1 === $factor ? $value : self::gcd($factor, $value);
        }

        return $factor;
    }

    private static function gcd(int $a, int $b): int
    {
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return \abs($a);
    }
}
