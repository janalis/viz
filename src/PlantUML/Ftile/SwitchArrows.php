<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * The arrows of a `switch`, as PlantUML draws them: from its test's diamond across and down
 * into the first and the last case, straight down into those between, and out of each case
 * that goes on to the diamond below — down into it when it is alone.
 */
final readonly class SwitchArrows
{
    private const float MARGIN = 10.0;

    /**
     * @param list<?TextBlock> $positives each case's label on the arrow into it
     * @param list<?TextBlock> $specials each case's label on the arrow out of it
     */
    public function __construct(
        private FtileSwitch $switch,
        private Rainbow $arrow,
        private array $positives,
        private array $specials,
    ) {}

    /** @return list<Snake> */
    public function snakes(): array
    {
        if (1 === \count($this->switch->tiles)) {
            return $this->one();
        }
        $last = \count($this->switch->tiles) - 1;
        $snakes = [$this->across(0), $this->across($last)];
        for ($i = 1; $i < $last; ++$i) {
            $snakes[] = $this->top($i);
        }

        return [...$snakes, ...$this->outgoing()];
    }

    /** @return list<Snake> into the one case, and out of it when it goes on */
    private function one(): array
    {
        $tile = $this->switch->tiles[0];
        [, $y1] = $this->onDiamond1($this->switch->diamond1->geometry()->out());
        [$x2, $y2] = $this->in(0);
        $snakes = [Snake::through($this->arrow, Snake::arrow('D'), [[$x2, $y1], [$x2, $y2]])->withLabel($this->positives[0])];
        if ($tile->geometry()->hasOut()) {
            [, $y1] = $this->out(0);
            [$x2, $y2] = $this->onDiamond2($this->switch->diamond2->geometry()->in());
            $snakes[] = Snake::through($this->arrow, Snake::arrow('D'), [[$x2, $y1], [$x2, $y2]]);
        }

        return $snakes;
    }

    /** @return list<Snake> out of the cases that go on */
    private function outgoing(): array
    {
        $tiles = $this->switch->tiles;
        $outs = \array_keys(\array_filter($tiles, static fn (Ftile $tile): bool => $tile->geometry()->hasOut()));
        if ([] === $outs) {
            return [];
        }
        $last = \max($outs);
        $first = \min($outs) < (\count($tiles) - 1) ? \min($outs) : \count($tiles);
        $snakes = [];
        if ($first < \count($tiles)) {
            $snakes[] = $this->toDiamond2($first);
        }
        if ($last > 0) {
            $snakes[] = $this->toDiamond2($last);
        }
        foreach ($outs as $index) {
            if ($index <= $first || $index >= $last) {
                continue;
            }
            $snakes[] = $this->bottom($index);
        }

        return $snakes;
    }

    /** From a side of the test's diamond across and down into the first or the last case, round under the diamond when the last is left of it. */
    private function across(int $index): Snake
    {
        $diamond = $this->switch->diamond1->geometry();
        $first = 0 === $index;
        [$x1, $y1] = $this->onDiamond1($first ? $diamond->pointD() : $diamond->pointB());
        [$x2, $y2] = $this->in($index);
        $points = !$first && $x1 > $x2
            ? [[$x1, $y1], [$x1 + FtileDiamond::HALF, $y1], [$x1 + FtileDiamond::HALF, $y1 + $diamond->height], [$x2, $y1 + $diamond->height]]
            : [[$x1, $y1], [$x2, $y1]];

        return Snake::through($this->arrow, Snake::arrow('D'), [...$points, [$x2, $y2]])->withLabel($this->positives[$index]);
    }

    /** Down into a case between the first and the last: straight from the diamond's side, or from under it when the case stands below it. */
    private function top(int $index): Snake
    {
        $diamond = $this->switch->diamond1->geometry();
        [$xb] = $this->onDiamond1($diamond->pointB());
        [$x1, $y1] = $this->onDiamond1($diamond->out());
        [$xd, $yd] = $this->onDiamond1($diamond->pointD());
        [$x2, $y2] = $this->in($index);
        $middle = (($y1 * 2) + $y2) / 3;
        $points =
            $x2 < ($xd - self::MARGIN) || $x2 > ($xb + self::MARGIN)
                ? [[$x2, $yd], [$x2, $y2]]
                : [[$x1, $y1], [$x1, $middle], [$x2, $middle], [$x2, $y2]];

        return Snake::through($this->arrow, Snake::arrow('D'), $points)->withLabel($this->positives[$index], 'center');
    }

    /** Out of the first or the last case that goes on, down and across to the diamond below, or down into it. */
    private function toDiamond2(int $index): Snake
    {
        $diamond = $this->switch->diamond2->geometry();
        [$x1, $y1] = $this->out($index);
        [$xd, $yd] = $this->onDiamond2($diamond->pointD());
        [$xb, $yb] = $this->onDiamond2($diamond->pointB());
        [$x2, $y2, $head] = match (true) {
            $x1 < $xd => [$xd, $yd, 'R'],
            $x1 > $xb => [$xb, $yb, 'L'],
            default => [...$this->onDiamond2($diamond->in()), 'D'],
        };
        $points =
            'L' === $head && $x2 > ($x1 - 10)
                ? [[$x1, $y1], [$x1, $y2 - 8], [$x1 + 12, $y2 - 8], [$x1 + 12, $y2]]
                : [[$x1, $y1], [$x1, $y2]];

        return Snake::through($this->arrow, Snake::arrow($head), [...$points, [$x2, $y2]])->withLabel($this->specials[$index], 'center');
    }

    /** Out of a case between, down to the line into the diamond below, or round to it when the case stands above it. */
    private function bottom(int $index): Snake
    {
        $diamond1 = $this->switch->diamond1->geometry();
        [$xb] = $this->onDiamond1($diamond1->pointB());
        [$xd] = $this->onDiamond1($diamond1->pointD());
        $diamond2 = $this->switch->diamond2->geometry();
        [$x2, $y2] = $this->onDiamond2($diamond2->in());
        [, $yd] = $this->onDiamond2($diamond2->pointD());
        [$x1, $y1] = $this->out($index);
        $middle = ($y1 + $y2) / 2;
        $points =
            $x1 < ($xd - self::MARGIN) || $x1 > ($xb + self::MARGIN)
                ? [[$x1, $y1], [$x1, $yd]]
                : [[$x1, $y1], [$x1, $middle], [$x2, $middle], [$x2, $y2]];

        return Snake::through($this->arrow, Snake::arrow('D'), $points)->withLabel($this->specials[$index], 'center');
    }

    /** @return array{float, float} where the flow comes into a case */
    private function in(int $index): array
    {
        return self::at($this->switch->tileAt($index), $this->switch->tiles[$index]->geometry()->in());
    }

    /** @return array{float, float} where the flow leaves a case */
    private function out(int $index): array
    {
        return self::at($this->switch->tileAt($index), $this->switch->tiles[$index]->geometry()->out());
    }

    /**
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private function onDiamond1(array $point): array
    {
        return self::at($this->switch->diamond1At(), $point);
    }

    /**
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private function onDiamond2(array $point): array
    {
        return self::at($this->switch->diamond2At(), $point);
    }

    /**
     * @param array{float, float} $at
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private static function at(array $at, array $point): array
    {
        return [$at[0] + $point[0], $at[1] + $point[1]];
    }
}
