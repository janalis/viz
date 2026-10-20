<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A `repeat` loop, as PlantUML lays it out: the diamond — or the action — it starts at, its
 * steps under it, its test's diamond under them, and the arrow back from that diamond's right
 * corner up past the steps' right into the first diamond — through its backward action when
 * it has one.
 */
final class FtileRepeat extends AbstractFtile
{
    private const float HALF = FtileDiamond::HALF;

    public function __construct(
        private readonly Ftile $block,
        private readonly Ftile $diamond1,
        private readonly Ftile $diamond2,
        /** Its test beside its diamond, which it must be wide enough for. */
        private readonly TextBlock $test,
        /** The arrow into its steps, with its label. */
        private readonly Rainbow $in,
        private readonly ?TextBlock $inLabel,
        /** The arrow back, with its label. */
        private readonly Rainbow $incoming1,
        private readonly ?TextBlock $back1,
        /** The arrow back from the backward action, with its label. */
        private readonly Rainbow $incoming2,
        private readonly ?TextBlock $back2,
        /** The arrow out of its steps, with its label. */
        private readonly Rainbow $out,
        private readonly ?TextBlock $outLabel,
        private readonly ?Ftile $backward = null,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        [$width, $height] = $this->internal();

        return new Geometry($width, $height, $this->left(), 0.0, $height);
    }

    #[\Override]
    public function children(): array
    {
        return [$this->block, $this->diamond1, $this->diamond2, ...(null === $this->backward ? [] : [$this->backward])];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        [$width, $height] = $this->internal();
        $left = $this->left();
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();

        return match ($child) {
            $this->block => [$left - $this->block->geometry()->left, $diamond1->height + (($height - $diamond1->height - $diamond2->height - $this->block->geometry()->height) / 2)],
            $this->diamond1 => [$left - ($diamond1->width / 2), 0.0],
            $this->diamond2 => [$left - ($diamond2->width / 2), $height - $diamond2->height],
            default => [$width - (float) $this->backward?->geometry()->width, ($height - (float) $this->backward?->geometry()->height) / 2],
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->children() as $tile) {
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->moved($dx, $dy));
        }
        [$x1, $y1] = $this->at($this->diamond1, $this->diamond1->geometry()->out());
        [$x2, $y2] = $this->at($this->block, $this->block->geometry()->in());
        $points = $x1 === $x2 ? [[$x1, $y1], [$x2, $y2]] : [[$x1, $y1], [$x1, ($y1 + $y2) / 2], [$x2, ($y1 + $y2) / 2], [$x2, $y2]];
        $canvas->snake(Snake::through($this->in, Snake::arrow('D'), $points)->withLabel($this->inLabel));
        null === $this->backward ? $this->drawBack($canvas) : $this->drawBackward($canvas, $this->backward);
        $block = $this->block->geometry();
        if ($block->hasOut()) {
            [$x1, $y1] = $this->at($this->block, $block->out());
            [$x2, $y2] = $this->at($this->diamond2, $this->diamond2->geometry()->in());
            $canvas->snake(Snake::through($this->out, Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]])->withLabel($this->outLabel));
        }
    }

    /** From the test's diamond's right corner up past the steps' right into the first diamond's. */
    private function drawBack(Canvas $canvas): void
    {
        $diamond2 = $this->diamond2->geometry();
        [$x1, $y1] = $this->at($this->diamond2, [$diamond2->width, $diamond2->height / 2]);
        [$x2, $y2] = $this->diamond1Right();
        $right = $this->internal()[0] - self::HALF;
        $canvas->snake(
            Snake::through($this->incoming1, Snake::arrow('L'), [[$x1, $y1], [$right, $y1], [$right, $y2], [$x2, $y2]])
                ->emphasizing('U')
                ->withLabel($this->back1),
        );
    }

    /** From the test's diamond into the backward action, and from it into the first diamond. */
    private function drawBackward(Canvas $canvas, Ftile $backward): void
    {
        $diamond2 = $this->diamond2->geometry();
        $geometry = $backward->geometry();
        [$x, $y] = $this->translateFor($this->diamond2);
        [$x2, $y2] = $this->at($backward, [$geometry->left, (float) $geometry->outY]);
        $x1 = $x2 < ($x + ($diamond2->width / 2)) ? $x : $x + $diamond2->width;
        $y1 = $y + ($diamond2->height / 2);
        $canvas->snake(Snake::through($this->incoming1, Snake::arrow('U'), [[$x1, $y1], [$x2, $y1], [$x2, $y2]])->withLabel($this->back1));
        [$x1, $y1] = $this->at($backward, [$geometry->left, $geometry->inY]);
        [$x2, $y2] = $this->diamond1Right();
        $canvas->snake(Snake::through($this->incoming2, Snake::arrow('L'), [[$x1, $y1], [$x1, $y2], [$x2, $y2]])->withLabel($this->back2));
    }

    /** @return array{float, float} */
    private function diamond1Right(): array
    {
        $diamond1 = $this->diamond1->geometry();

        return $this->at($this->diamond1, [$diamond1->width, $diamond1->height / 2]);
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

    /** @return array{float, float} */
    private function internal(): array
    {
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();
        $block = $this->block->geometry();
        $width = \max($this->left() + \max($block->width - $block->left, $diamond1->width / 2, $diamond2->width / 2), $this->test->size()[0] + (2 * self::HALF));
        $width += null === $this->backward ? 0.0 : $this->backward->geometry()->width;

        return [$width + (2 * self::HALF), $diamond1->height + $block->height + $diamond2->height + (8 * self::HALF)];
    }

    /** Where its flow goes down: far enough right for its steps and its diamonds. */
    private function left(): float
    {
        return \max($this->block->geometry()->left, $this->diamond1->geometry()->width / 2, $this->diamond2->geometry()->width / 2);
    }
}
