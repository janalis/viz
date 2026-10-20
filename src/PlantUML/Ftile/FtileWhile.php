<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A `while` loop, as PlantUML lays it out: its test's diamond, its steps under it, the arrow
 * back from them round their right into the diamond's right corner — through its backward
 * action when it has one — and the way out from the diamond's left corner down its left side,
 * or across to the stop or end written after it. Its breaks leave by arrows to its left side.
 */
final class FtileWhile extends AbstractFtile
{
    private const float HALF = FtileDiamond::HALF;

    public function __construct(
        private readonly Ftile $block,
        private readonly FtileDiamond $diamond,
        private readonly Rainbow $arrow,
        /** The arrow back, after the steps, with its label. */
        private readonly Rainbow $incoming1,
        private readonly TextBlock $back1,
        /** The arrow back from the backward action, with its label. */
        private readonly Rainbow $incoming2,
        private readonly TextBlock $back2,
        private readonly Rainbow $out,
        private readonly ?Ftile $special = null,
        private readonly ?Ftile $backward = null,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        $diamond = $this->diamond->geometry();
        $geometry = $diamond->appendBottom($this->block->geometry());
        $height = $geometry->height + (4 * self::HALF) + $this->back1->size()[1];
        $special = $this->specialWidth();
        $backward = null === $this->backward ? 0.0 : $this->backward->geometry()->width;

        return new Geometry($special + $geometry->width + (3 * self::HALF) + $backward, $height, $special + $geometry->left + (2 * self::HALF), $diamond->inY, $height);
    }

    #[\Override]
    public function children(): array
    {
        return [$this->block, $this->diamond, ...(null === $this->special ? [] : [$this->special]), ...(null === $this->backward ? [] : [$this->backward])];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return match ($child) {
            $this->block => $this->blockAt(),
            $this->diamond => $this->diamondAt(),
            $this->special => $this->specialAt(),
            default => $this->backwardAt(),
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->children() as $tile) {
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->moved($dx, $dy));
        }
        $block = $this->block->geometry();
        if (0.0 === $block->width || 0.0 === $block->height) {
            $this->drawBack($canvas, $this->at($this->diamond, $this->diamond->geometry()->out()), $this->incoming1, null);
        }
        if (0.0 !== $block->width && 0.0 !== $block->height) {
            [$x1, $y1] = $this->at($this->diamond, $this->diamond->geometry()->out());
            [$x2, $y2] = $this->at($this->block, $block->in());
            $canvas->snake(Snake::through($this->arrow->over($this->block->inLink()->color), Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]]));
            $this->drawBackFromBlock($canvas);
        }
        $this->drawOut($canvas);
        $this->drawBreaks($canvas);
    }

    /** From the steps back into the diamond: round their right, or through the backward action. */
    private function drawBackFromBlock(Canvas $canvas): void
    {
        $block = $this->block->geometry();
        if (!$block->hasOut()) {
            return;
        }
        $from = $this->at($this->block, $block->out());
        $tile = $this->backward;
        if (null === $tile) {
            $this->drawBack($canvas, $from, $this->incoming1, $this->back1);

            return;
        }
        $backward = $tile->geometry();
        [$x1, $y1] = $from;
        [$x2, $y2] = $this->at($tile, [$backward->left, (float) $backward->outY]);
        $below = \max($y1, $this->bottom()) + self::HALF;
        $canvas->add(Shape::empty($x1, $below, 5.0, self::HALF));
        $canvas->snake(Snake::through($this->incoming1, Snake::arrow('U'), [[$x1, $y1], [$x1, $below], [$x2, $below], [$x2, $y2]])->withLabel($this->back1, 'bottom'));
        [$x1, $y1] = $this->at($tile, [$backward->left, $backward->inY]);
        [$x2, $y2] = $this->diamondRight();
        $canvas->snake(Snake::through($this->incoming2, Snake::arrow('L'), [[$x1, $y1], [$x1, $y2], [$x2, $y2]])->withLabel($this->back2));
    }

    /**
     * Down below the steps, across past their right, up and into the diamond's right corner.
     *
     * @param array{float, float} $from
     */
    private function drawBack(Canvas $canvas, array $from, Rainbow $color, ?TextBlock $label): void
    {
        [$x1, $y1] = $from;
        [$x2, $y2] = $this->diamondRight();
        $below = \max($y1, $this->bottom()) + self::HALF;
        $right = $this->geometry()->width;
        $snake = Snake::through($color, Snake::arrow('L'), [[$x1, $y1], [$x1, $below], [$right, $below], [$right, $y2], [$x2, $y2]])->emphasizing('U');
        $canvas->snake($snake->withLabel($label, 'bottom'));
        $canvas->add(Shape::empty($x1, $below, 5.0, self::HALF));
    }

    /** From the diamond's left corner down its left side to the way out, or across to the stop after it. */
    private function drawOut(Canvas $canvas): void
    {
        [$x1] = $this->diamondAt();
        $y1 = $this->middle();
        $special = $this->special;
        if (null !== $special) {
            [$x2, $y2] = $this->at($special, $special->geometry()->in());
            $canvas->snake(Snake::through($this->out, Snake::arrow('D'), [[$x1, $y1], [$x2, $y1], [$x2, $y2]]));

            return;
        }
        $total = $this->geometry();
        $canvas->snake(
            Snake::through($this->out, null, [[$x1, $y1], [self::HALF, $y1], [self::HALF, $total->height]])
                ->withMerge(MergeStrategy::Limited)
                ->emphasizing('D'),
        );
        $canvas->snake(Snake::through($this->out, null, [[self::HALF, $total->height], [$total->left, $total->height]]));
    }

    /** From each break inside across to its left side. */
    private function drawBreaks(Canvas $canvas): void
    {
        $weldings = $this->block->weldings();
        if ([] === $weldings) {
            return;
        }
        $genealogy = new Genealogy($this);
        foreach ($weldings as $break) {
            [$x, $y] = $genealogy->translate($break);
            $canvas->snake(Snake::through($this->arrow, Snake::arrow('L'), [[$x, $y], [self::HALF, $y]]));
        }
    }

    /**
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private function at(Ftile $tile, array $point): array
    {
        [$dx, $dy] = $this->translateFor($tile);

        return [$point[0] + $dx, $point[1] + $dy];
    }

    /** @return array{float, float} the diamond's right corner */
    private function diamondRight(): array
    {
        return [$this->diamondAt()[0] + $this->diamond->geometry()->width, $this->middle()];
    }

    /** Half way between the diamond's ways in and out. */
    private function middle(): float
    {
        $diamond = $this->diamond->geometry();

        return $diamond->inY + (((float) $diamond->outY - $diamond->inY) / 2);
    }

    /** Where the steps end. */
    private function bottom(): float
    {
        return $this->blockAt()[1] + $this->block->geometry()->height;
    }

    private function specialWidth(): float
    {
        return null === $this->special ? 0.0 : $this->special->geometry()->width;
    }

    /** @return array{float, float} */
    private function blockAt(): array
    {
        $total = $this->geometry();
        $diamond = $this->diamond->geometry()->height;
        $block = $this->block->geometry();

        return [$total->left - $block->left, $diamond + (($total->height - $diamond - $block->height - $this->back1->size()[1]) / 2)];
    }

    /** @return array{float, float} */
    private function diamondAt(): array
    {
        return [$this->geometry()->left - $this->diamond->geometry()->left, 0.0];
    }

    /** @return array{float, float} */
    private function specialAt(): array
    {
        $diamond = $this->diamond->geometry();
        $half = ((float) $diamond->outY - $diamond->inY) / 2;

        return [\min($this->blockAt()[0] - self::HALF, $this->diamondAt()[0]) - $this->specialWidth(), \max(3 * $half, 4 * self::HALF)];
    }

    /** @return array{float, float} */
    private function backwardAt(): array
    {
        $total = $this->geometry();
        $backward = $this->backward?->geometry();

        return [$total->width - (float) $backward?->width, ($total->height - (float) $backward?->height) / 2];
    }
}
