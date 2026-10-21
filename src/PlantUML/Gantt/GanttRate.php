<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/** PlantUML's `Fraction`: a rate of work, as a reduced fraction. */
final readonly class GanttRate
{
    public int $num;
    public int $den;

    public function __construct(int $num, int $den = 1)
    {
        if ($den < 0) {
            [$num, $den] = [-$num, -$den];
        }
        $gcd = self::gcd(\abs($num), $den);
        [$this->num, $this->den] = [\intdiv($num, \max(1, $gcd)), \intdiv($den, \max(1, $gcd))];
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function one(): self
    {
        return new self(1);
    }

    public function times(self $other): self
    {
        return new self($this->num * $other->num, $this->den * $other->den);
    }

    public function plus(self $other): self
    {
        return new self(($this->num * $other->den) + ($other->num * $this->den), $this->den * $other->den);
    }

    public function max(self $other): self
    {
        return ($this->num * $other->den) >= ($other->num * $this->den) ? $this : $other;
    }

    public function zeroed(): bool
    {
        return 0 === $this->num;
    }

    private static function gcd(int $a, int $b): int
    {
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
