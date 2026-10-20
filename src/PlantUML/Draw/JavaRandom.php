<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/**
 * Java's `java.util.Random`, the linear congruential generator its documentation specifies,
 * so that a shape PlantUML draws with seeded randomness comes out the same.
 */
final class JavaRandom
{
    private const int MULTIPLIER = 0x5_DEEC_E66D;
    private const int ADDEND = 0xB;
    private const int MASK = (1 << 48) - 1;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = ($seed ^ self::MULTIPLIER) & self::MASK;
    }

    public function nextDouble(): float
    {
        return (($this->next(26) << 27) + $this->next(27)) / (float) (1 << 53);
    }

    private function next(int $bits): int
    {
        // The 48-bit product, taken in two halves so that it stays within PHP's integers.
        $high = $this->seed >> 24;
        $low = $this->seed & 0xFF_FFFF;
        $product = (((($high * self::MULTIPLIER) & 0xFF_FFFF) << 24) + ($low * self::MULTIPLIER)) & self::MASK;
        $this->seed = ($product + self::ADDEND) & self::MASK;

        return $this->seed >> (48 - $bits);
    }
}
