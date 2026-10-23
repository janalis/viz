<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * The points an arrow goes through, as PlantUML's worm holds them: consecutive duplicates
 * dropped, and when two arrows meet end to start, merged with the turns that cancel out
 * smoothed away.
 */
final class Worm
{
    /** @var list<array{float, float}> */
    public array $points = [];

    public function add(float $x, float $y): void
    {
        $last = \end($this->points);
        if (false !== $last && $last[0] === $x && $last[1] === $y) {
            return;
        }
        $this->points[] = [$x, $y];
    }

    public function moved(float $dx, float $dy): self
    {
        $worm = new self();
        $worm->points = \array_map(static fn (array $point): array => [$point[0] + $dx, $point[1] + $dy], $this->points);

        return $worm;
    }

    /** @return array{float, float} */
    public function first(): array
    {
        return $this->points[0];
    }

    /** @return array{float, float} */
    public function last(): array
    {
        return $this->points[\count($this->points) - 1];
    }

    /** Each step's direction, `U`, `D`, `L` or `R`, `X` aslant. */
    public function directions(): string
    {
        $code = '';
        for ($i = 0, $count = \count($this->points); $i < ($count - 1); ++$i) {
            $code .= self::direction($this->points[$i], $this->points[$i + 1]) ?? '';
        }

        return $code;
    }

    /** Whether it is one stretch across. */
    public function horizontal(): bool
    {
        return 2 === \count($this->points) && \abs($this->points[0][1] - $this->points[1][1]) < 1e-6;
    }

    /** It followed by another starting where it ends, the turns that cancel out smoothed away. */
    public function merge(self $other, MergeStrategy $strategy): self
    {
        $worm = new self();
        foreach ([...$this->points, ...$other->points] as [$x, $y]) {
            $worm->add($x, $y);
        }
        do {
            $changed =
                $worm->removeNullVector()
                || $worm->removeRedundantDirection()
                || $worm->removePattern1()
                || $worm->removePattern2()
                || $worm->removePattern3()
                || $worm->removePattern4()
                || $worm->removePattern5()
                || $worm->removePattern6()
                || $worm->removePattern7()
                || (MergeStrategy::Full === $strategy && $worm->removePattern8());
        } while ($changed);

        return $worm;
    }

    /**
     * The direction from one point to another, null for the same point, `X` aslant.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    public static function direction(array $from, array $to): ?string
    {
        // Points a rounding apart stand level, or one above the other.
        [$across, $down] = [\abs($to[0] - $from[0]) < 1e-6, \abs($to[1] - $from[1]) < 1e-6];

        return match (true) {
            $across && $down => null,
            $across => $to[1] > $from[1] ? 'D' : 'U',
            $down => $to[0] > $from[0] ? 'R' : 'L',
            default => 'X',
        };
    }

    private function at(int $i): ?string
    {
        return self::direction($this->points[$i], $this->points[$i + 1]);
    }

    /** The directions of the four steps from a point, padded with blanks past the end. */
    private function pattern(int $i): string
    {
        $code = '';
        for ($k = $i; $k < ($i + 4); ++$k) {
            $code .= \array_key_exists($k + 1, $this->points) ? $this->at($k) ?? '-' : '-';
        }

        return $code;
    }

    /** @param array{float, float} $point */
    private function replace(int $from, int $count, array $point): void
    {
        \array_splice($this->points, $from, $count, [$point]);
    }

    private function removeNullVector(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 1); ++$i) {
            if (null !== $this->at($i)) {
                continue;
            }

            \array_splice($this->points, $i, length: 1);

            return true;
        }

        return false;
    }

    private function removeRedundantDirection(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 2); ++$i) {
            if ($this->at($i) !== $this->at($i + 1)) {
                continue;
            }

            \array_splice($this->points, $i + 1, length: 1);

            return true;
        }

        return false;
    }

    private function removePattern1(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 5); ++$i) {
            if (!\in_array($this->pattern($i), ['DLDR', 'DRDL'], strict: true)) {
                continue;
            }

            $this->replace($i + 1, 3, [$this->points[$i + 1][0], $this->points[$i + 3][1]]);

            return true;
        }

        return false;
    }

    private function removePattern2(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 5); ++$i) {
            if (!\in_array($this->pattern($i), ['RDRU', 'LDLU'], strict: true)) {
                continue;
            }

            $this->replace($i + 1, 3, [$this->points[$i + 3][0], $this->points[$i + 1][1]]);

            return true;
        }

        return false;
    }

    private function removePattern3(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 4); ++$i) {
            if (!\in_array($this->pattern($i), ['DRDR', 'DLDL'], strict: true)) {
                continue;
            }

            $this->replace($i + 1, 3, [$this->points[$i + 1][0], $this->points[$i + 3][1]]);

            return true;
        }

        return false;
    }

    private function removePattern4(): bool
    {
        $i = \count($this->points) - 5;
        if ($i < 0 || 'DLDR' !== $this->pattern($i) || $this->points[$i + 4][0] <= $this->points[$i + 1][0]) {
            return false;
        }
        $this->replace($i + 1, 3, [$this->points[$i + 1][0], $this->points[$i + 3][1]]);

        return true;
    }

    private function removePattern5(): bool
    {
        $i = \count($this->points) - 5;
        if ($i < 0 || 'DRDL' !== $this->pattern($i) || ($this->points[$i + 4][0] + 4) >= $this->points[$i + 1][0]) {
            return false;
        }
        $this->replace($i + 1, 3, [$this->points[$i + 1][0], $this->points[$i + 3][1]]);

        return true;
    }

    private function removePattern6(): bool
    {
        $inverse = ['U' => 'D', 'D' => 'U', 'L' => 'R', 'R' => 'L'];
        for ($i = 0, $count = \count($this->points); $i < ($count - 2); ++$i) {
            if (($inverse[$this->at($i) ?? ''] ?? null) !== $this->at($i + 1)) {
                continue;
            }

            \array_splice($this->points, $i + 1, length: 1);

            return true;
        }

        return false;
    }

    private function removePattern7(): bool
    {
        if (\count($this->points) <= 4 || 'RDLD' !== $this->pattern(0) || $this->points[3][0] <= $this->points[0][0]) {
            return false;
        }
        $this->replace(1, 2, [$this->points[3][0], $this->points[0][1]]);

        return true;
    }

    private function removePattern8(): bool
    {
        for ($i = 0, $count = \count($this->points); $i < ($count - 4); ++$i) {
            if (!\in_array($this->pattern($i), ['LDLD', 'RDRD'], strict: true)) {
                continue;
            }

            $this->replace($i + 1, 3, [$this->points[$i + 3][0], $this->points[$i + 1][1]]);

            return true;
        }

        return false;
    }
}
