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
        /** The switch's own swimlane. */
        private ?string $lane = null,
    ) {}

    /**
     * Each arrow, the swimlanes it leaves and reaches, and its route across them — none where
     * PlantUML draws none.
     *
     * @return list<array{Snake, ?string, ?string, \Closure(float, float): list<Snake|Shape>}>
     */
    public function links(): array
    {
        $none = static fn (): array => [];
        if (1 === \count($this->switch->tiles)) {
            return $this->one($none);
        }
        $last = \count($this->switch->tiles) - 1;
        $links = [];
        foreach ($this->switch->tiles as $index => $tile) {
            $snake = 0 === $index || $last === $index ? $this->across($index) : $this->top($index);
            $links[] = [$snake, null, FtileLane::in($tile), $this->crossIn($index)];
        }

        return [...$links, ...$this->outgoing($none)];
    }

    /**
     * Into the one case, and out of it when it goes on — neither across swimlanes, as PlantUML.
     *
     * @param \Closure(): list<Snake> $none
     * @return list<array{Snake, ?string, ?string, \Closure(float, float): list<Snake|Shape>}>
     */
    private function one(\Closure $none): array
    {
        $tile = $this->switch->tiles[0];
        [, $y1] = $this->onDiamond1($this->switch->diamond1->geometry()->out());
        [$x2, $y2] = $this->in(0);
        $links = [[Snake::through($this->arrow, Snake::arrow('D'), [[$x2, $y1], [$x2, $y2]])->withLabel($this->positives[0]), null, FtileLane::in($tile), $none]];
        if ($tile->geometry()->hasOut()) {
            [, $y1] = $this->out(0);
            [$x2, $y2] = $this->onDiamond2($this->switch->diamond2->geometry()->in());
            $links[] = [Snake::through($this->arrow, Snake::arrow('D'), [[$x2, $y1], [$x2, $y2]]), FtileLane::out($tile), null, $none];
        }

        return $links;
    }

    /**
     * Out of the cases that go on: the first and the last of those in the switch's swimlane
     * across to the diamond below, those between down to it, and those standing elsewhere bent
     * across into its side — as PlantUML, none when no case of its swimlane goes on.
     *
     * @param \Closure(): list<Snake> $none
     * @return list<array{Snake, ?string, ?string, \Closure(float, float): list<Snake|Shape>}>
     */
    private function outgoing(\Closure $none): array
    {
        $tiles = $this->switch->tiles;
        $outs = \array_keys(\array_filter($tiles, static fn (Ftile $tile): bool => $tile->geometry()->hasOut()));
        $here = \array_values(\array_filter($outs, fn (int $index): bool => !$this->elsewhere(FtileLane::in($tiles[$index]))));
        if ([] === $here) {
            return [];
        }
        $last = \max($here);
        $first = \min($here) < (\count($tiles) - 1) ? \min($here) : \count($tiles);
        $links = [];
        if ($first < \count($tiles)) {
            $links[] = [$this->toDiamond2($first), FtileLane::out($tiles[$first]), null, $none];
        }
        if ($last > 0) {
            $links[] = [$this->toDiamond2($last), FtileLane::out($tiles[$last]), null, $none];
        }
        foreach ($outs as $index) {
            if ($index > $first && $index < $last) {
                $links[] = [$this->bottom($index), FtileLane::out($tiles[$index]), null, $none];
            }
            if ($this->elsewhere(FtileLane::out($tiles[$index]))) {
                $links[] = [$this->toDiamond2($index), FtileLane::out($tiles[$index]), null, $this->crossOut($index)];
            }
        }

        return $links;
    }

    /** Whether a case's swimlane is not the switch's. */
    private function elsewhere(?string $lane): bool
    {
        return null !== $lane && null !== $this->lane && $lane !== $this->lane;
    }

    /**
     * As PlantUML, into a case in another swimlane: from the side of the test's diamond facing it, across and down.
     *
     * @return \Closure(float, float): list<Snake|Shape>
     */
    private function crossIn(int $index): \Closure
    {
        $diamond = $this->switch->diamond1->geometry();
        [$x1, $y1] = $this->onDiamond1($diamond->out());
        [$x2, $y2] = $this->in($index);
        $arrow = $this->arrow;
        $label = $this->positives[$index];

        return static function (float $t1, float $t2) use ($x1, $y1, $x2, $y2, $diamond, $arrow, $label): array {
            [$ax, $bx] = [$x1 + $t1, $x2 + $t2];
            $y = $y1 - ($diamond->height / 2);
            $start = $ax > $bx ? $ax - ($diamond->width / 2) : $ax + ($diamond->width / 2);

            return [Snake::through($arrow, Snake::arrow('D'), [[$start, $y], [$bx, $y], [$bx, $y2]])->withLabel($label)];
        };
    }

    /**
     * As PlantUML, out of a case in another swimlane: down, then across into the side of the diamond below facing it.
     *
     * @return \Closure(float, float): list<Snake|Shape>
     */
    private function crossOut(int $index): \Closure
    {
        $diamond = $this->switch->diamond2->geometry();
        [$x1, $y1] = $this->out($index);
        [$x2, $y2] = $this->onDiamond2($diamond->in());
        $arrow = $this->arrow;

        return static function (float $t1, float $t2) use ($x1, $y1, $x2, $y2, $diamond, $arrow): array {
            [$ax, $bx] = [$x1 + $t1, $x2 + $t2];
            $y = $y2 + ($diamond->height / 2);
            $left = $ax > $bx;

            return [Snake::through($arrow, Snake::arrow($left ? 'L' : 'R'), [[$ax, $y1], [$ax, $y], [$left ? $bx + ($diamond->width / 2) : $bx - ($diamond->width / 2), $y]])];
        };
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
