<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A decision with `elseif`s laid out down, as PlantUML lays it out under `!pragma
 * useVerticalIf on`: each test's diamond on the left, one under the other 20 apart, its branch
 * right of it; the `else` branch under them all; and the branches' ways out round the right
 * into a small diamond at the bottom.
 */
final class FtileIfLongVertical extends AbstractFtile
{
    private const float SEPARATION = 20.0;
    private const float MARGIN_TOP = 30.0;
    private const float LAST_ELSE = 40.0;
    private const float STEP = 15.0;

    /**
     * @param non-empty-list<FtileDiamond> $diamonds each test's diamond, its branch's label east of it
     * @param non-empty-list<Ftile> $tiles each branch, room on its left for the labels into the tests
     * @param list<BranchTile> $thens
     * @param list<?TextBlock> $inlabels each branch's label written before its `elseif`, on the arrow from the test before
     */
    public function __construct(
        private readonly array $diamonds,
        private readonly array $tiles,
        private readonly Ftile $tile2,
        private readonly FtileDiamond $last,
        private readonly array $thens,
        private readonly Rainbow $arrow,
        /** The arrow into the decision, and from its last test into the `else` branch. */
        private readonly Rainbow $in,
        private readonly ?TextBlock $elseLabel,
        private readonly array $inlabels,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        $internal = $this->internal();
        foreach ([...$this->tiles, $this->tile2] as $tile) {
            if ($tile->geometry()->hasOut()) {
                return new Geometry($internal->width, $internal->height, $internal->left, 0.0, $internal->height);
            }
        }

        return $internal;
    }

    #[\Override]
    public function children(): array
    {
        return [...$this->tiles, $this->tile2];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        if ($child === $this->tile2) {
            return $this->tile2At();
        }

        return $this->tileAt((int) \array_search($child, $this->tiles, strict: true));
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->tiles as $index => $tile) {
            $tile->draw($canvas->moved(...$this->tileAt($index)));
        }
        foreach ($this->diamonds as $index => $diamond) {
            $diamond->draw($canvas->moved(...$this->diamondAt($index)));
        }
        $this->tile2->draw($canvas->moved(...$this->tile2At()));
        $this->last->draw($canvas->moved(...$this->lastAt()));
        $this->drawIn($canvas);
        foreach ($this->tiles as $index => $tile) {
            [$x1, $y1] = self::at($this->diamondAt($index), $this->diamonds[$index]->geometry()->pointB());
            [$x2, $y2] = self::at($this->tileAt($index), $tile->geometry()->in());
            $canvas->snake(Snake::through($this->thens[$index]->inColor($this->arrow), Snake::arrow('D'), [[$x1, $y1], [$x2, $y1], [$x2, $y2]]));
            if ($index > 0) {
                $this->drawBetween($canvas, $index);
            }
        }
        $this->drawOuts($canvas);
    }

    /** Into the first test, and from the last into the `else` branch. */
    private function drawIn(Canvas $canvas): void
    {
        $internal = $this->internal();
        [$x2, $y2] = self::at($this->diamondAt(0), $this->diamonds[0]->geometry()->in());
        $middle = $y2 / 2;
        $canvas->snake(Snake::through($this->in, Snake::arrow('D'), [[$internal->left, 0.0], [$internal->left, $middle], [$x2, $middle], [$x2, $y2]]));
        $last = \count($this->diamonds) - 1;
        [$x1, $y1] = self::at($this->diamondAt($last), $this->diamonds[$last]->geometry()->out());
        [$x2, $y2] = self::at($this->tile2At(), $this->tile2->geometry()->in());
        $points = [[$x1, $y1], [$x1, $y2 - self::STEP], [$x2, $y2 - self::STEP], [$x2, $y2]];
        $canvas->snake(Snake::through($this->in, Snake::arrow('D'), $points)->withLabel($this->elseLabel, 'center'));
    }

    /** From a test down to the next, labelled with what is written before the next. */
    private function drawBetween(Canvas $canvas, int $index): void
    {
        [$x1, $y1] = self::at($this->diamondAt($index - 1), $this->diamonds[$index - 1]->geometry()->out());
        [$x2, $y2] = self::at($this->diamondAt($index), $this->diamonds[$index]->geometry()->in());
        $canvas->snake(Snake::through($this->arrow, Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]])->withLabel($this->inlabels[$index], 'center'));
    }

    /** Out of the first branch round the right into the small diamond, the others' to that way, and out of the `else` branch down into it. */
    private function drawOuts(Canvas $canvas): void
    {
        $width = $this->internal()->width;
        $lastGeometry = $this->last->geometry();
        [$lx, $ly] = self::at($this->lastAt(), $lastGeometry->in());
        foreach ($this->tiles as $index => $tile) {
            if (!$tile->geometry()->hasOut()) {
                continue;
            }
            [$x1, $y1] = self::at($this->tileAt($index), $tile->geometry()->out());
            $canvas->snake(
                0 === $index
                    ? Snake::through($this->arrow, Snake::arrow('L'), [
                        [$x1, $y1],
                        [$x1, $y1 + self::STEP],
                        [$width, $y1 + self::STEP],
                        [$width, $ly + ($lastGeometry->height / 2)],
                        [$lx + ($lastGeometry->width / 2), $ly + ($lastGeometry->height / 2)],
                    ])
                    : Snake::through($this->arrow, Snake::arrow('R'), [[$x1, $y1], [$x1, $y1 + self::STEP], [$width, $y1 + self::STEP]]),
            );
        }
        if (!$this->tile2->geometry()->hasOut()) {
            return;
        }
        [$x1, $y1] = self::at($this->tile2At(), $this->tile2->geometry()->out());
        $canvas->snake(Snake::through($this->arrow, Snake::arrow('D'), [[$x1, $y1], [$x1, $ly - self::STEP], [$lx, $ly - self::STEP], [$lx, $ly]]));
    }

    /** @return array{float, float} */
    private function tileAt(int $index): array
    {
        $diamonds = $this->diamondsWidth();
        $width = $this->internal()->width;

        return [$diamonds + (($width - $diamonds - $this->tiles[$index]->geometry()->width) / 2), $this->dy($index) + $this->diamonds[$index]->geometry()->height];
    }

    /** @return array{float, float} */
    private function diamondAt(int $index): array
    {
        return [($this->diamondsWidth() - $this->diamonds[$index]->geometry()->width) / 2, $this->dy($index)];
    }

    /** @return array{float, float} */
    private function tile2At(): array
    {
        return [($this->internal()->width - $this->tile2->geometry()->width) / 2, $this->dy(\count($this->tiles))];
    }

    /** @return array{float, float} */
    private function lastAt(): array
    {
        $internal = $this->internal();
        $last = $this->last->geometry();

        return [($internal->width - $last->width) / 2, $internal->height - $last->height];
    }

    /** How far down the test of a branch stands. */
    private function dy(int $index): float
    {
        $y = self::MARGIN_TOP;
        for ($i = 0; $i < $index; ++$i) {
            $y += $this->tiles[$i]->geometry()->height + $this->diamonds[$i]->geometry()->height + self::SEPARATION;
        }

        return $y;
    }

    private function diamondsWidth(): float
    {
        return \max(\array_map(static fn (FtileDiamond $diamond): float => $diamond->geometry()->width, $this->diamonds));
    }

    /** Its size, coming in at the middle of its top, and not leaving. */
    private function internal(): Geometry
    {
        $column1 = 0.0;
        $column2 = 0.0;
        $height = self::MARGIN_TOP;
        foreach ($this->tiles as $index => $tile) {
            $diamond = $this->diamonds[$index]->geometry();
            $height += $diamond->height + $tile->geometry()->height;
            $column1 = \max($column1, $diamond->width);
            $column2 = \max($column2, $tile->geometry()->width);
        }
        $width = $column1 + $column2;
        $result = new Geometry($width, $height, $width / 2, 0.0)
            ->appendBottom($this->tile2->geometry())
            ->addDim(0.0, (self::SEPARATION * \count($this->tiles)) + self::LAST_ELSE + $this->last->geometry()->height);

        return new Geometry($result->width, $result->height, $result->width / 2, 0.0);
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
