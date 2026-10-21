<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

/**
 * Branches stacked as PlantUML's `Tetris` stacks them: each a tee — its idea as thick and long
 * as it is, then its own branches as thick and long as they are — set below those before it as
 * close as their frontier lets either part, the whole then centred on its middle.
 */
final class Tetris
{
    /** @var list<array{float, float, float}> the frontier's stripes, from left to right: where each starts and ends, and how low it reaches */
    private array $frontier = [[-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX, -\PHP_FLOAT_MAX]];
    /** @var list<array{float, float, float, float}> each tee's thickness and length, then its branches' */
    private array $tees = [];
    /** @var list<float> where each tee's middle lies */
    private array $middles = [];
    private float $height = 0.0;

    /** @param list<array{float, float, float, float}> $tees */
    public function __construct(array $tees)
    {
        foreach ($tees as $tee) {
            $this->add($tee);
        }
        if ([] === $this->tees) {
            return;
        }
        [$top, $bottom] = [\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX];
        foreach ($this->tees as $index => [$thickness1, , $thickness2]) {
            $half = \max($thickness1, $thickness2) / 2;
            $top = \min($top, $this->middles[$index] - $half);
            $bottom = \max($bottom, $this->middles[$index] + $half);
        }
        $this->height = $bottom - $top;
        $mean = ($top + $bottom) / 2;
        $this->middles = \array_map(static fn (float $middle): float => $middle - $mean, $this->middles);
    }

    /** @return list<float> where each tee's middle lies, the whole centred on 0 */
    public function middles(): array
    {
        return $this->middles;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function width(): float
    {
        return \array_reduce($this->tees, static fn (float $width, array $tee): float => \max($width, $tee[1] + $tee[3]), initial: 0.0);
    }

    /** @param array{float, float, float, float} $tee */
    private function add(array $tee): void
    {
        [$thickness1, $length1, $thickness2, $length2] = $tee;
        $middle = 0.0;
        if ([] !== $this->tees) {
            $middle = \max($this->contact(0.0, $length1) + ($thickness1 / 2), $this->contact($length1, $length1 + $length2) + ($thickness2 / 2));
        }
        $this->tees[] = $tee;
        $this->middles[] = $middle;
        $this->segment(0.0, $length1, $middle + ($thickness1 / 2));
        if ($length2 > 0.0) {
            $this->segment($length1, $length1 + $length2, $middle + ($thickness2 / 2));
        }
    }

    /** How low the frontier reaches between two abscissas. */
    private function contact(float $from, float $to): float
    {
        return \array_reduce($this->colliding($from, $to), fn (float $low, int $index): float => \max($low, $this->frontier[$index][2]), -\PHP_FLOAT_MAX);
    }

    /** The frontier lowered to a value between two abscissas. */
    private function segment(float $from, float $to, float $value): void
    {
        $colliding = $this->colliding($from, $to);
        if (\count($colliding) > 1) {
            $starts = \array_map(fn (int $index): float => $this->frontier[$index][0], \array_slice($colliding, offset: 1));
            $x = $from;
            foreach ($starts as $start) {
                $this->segment($x, $start, $value);
                $x = $start;
            }
            $this->segment($x, $to, $value);

            return;
        }
        $index = $colliding[0];
        // A tee is set below the frontier, so its foot is always lower than the stripe it lies on.
        [$start, $end, $low] = $this->frontier[$index];
        $stripes = [];
        if ($start !== $from) {
            $stripes[] = [$start, $from, $low];
        }
        $stripes[] = [$from, $to, $value];
        if ($to !== $end) {
            $stripes[] = [$to, $end, $low];
        }
        \array_splice($this->frontier, $index, length: 1, replacement: $stripes);
    }

    /** @return list<int> the stripes between two abscissas */
    private function colliding(float $from, float $to): array
    {
        $colliding = [];
        foreach ($this->frontier as $index => [, $end]) {
            if ($from >= $end) {
                continue;
            }
            $colliding[] = $index;
            if ($to <= $end) {
                break;
            }
        }

        return $colliding;
    }
}
