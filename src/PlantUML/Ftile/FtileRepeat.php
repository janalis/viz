<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A `repeat` loop, as PlantUML lays it out: the diamond — or the action — it starts at, its
 * steps under it, its test's diamond under them, and the arrow back from that diamond's right
 * corner up past the steps' right into the first diamond — through its backward action when
 * it has one.
 */
final class FtileRepeat extends AbstractFtile implements LaneExit
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
        /** The way back without a backward action, as PlantUML picks it: round the steps' `right` or `left`, or `across` swimlanes. */
        private readonly string $back = 'right',
    ) {}

    /** As PlantUML, the flow leaves in the swimlane of its test. */
    #[\Override]
    public function exitLane(): ?string
    {
        return FtileLane::in($this->diamond2);
    }

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
            $tile->draw($canvas->spanning()->moved($dx, $dy));
        }
        [$x1, $y1] = $this->at($this->diamond1, $this->diamond1->geometry()->out());
        [$x2, $y2] = $this->at($this->block, $this->block->geometry()->in());
        $in = fn (float $t1, float $t2): array => [$this->inSnake([$x1 + $t1, $y1], [$x2 + $t2, $y2])];
        $canvas->link($in(t1: 0.0, t2: 0.0)[0], null, FtileLane::in($this->block), $in);
        match (true) {
            null !== $this->backward => $this->drawBackward($canvas, $this->backward),
            'left' === $this->back => $this->drawBackLeft($canvas),
            'across' === $this->back => $this->drawBackAcross($canvas),
            default => $this->drawBack($canvas),
        };
        $block = $this->block->geometry();
        if ($block->hasOut()) {
            [$x1, $y1] = $this->at($this->block, $block->out());
            [$x2, $y2] = $this->at($this->diamond2, $this->diamond2->geometry()->in());
            $out = $this->out;
            $label = $this->outLabel;
            $canvas->link(
                Snake::through($out, Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]])->withLabel($label),
                FtileLane::out($this->block),
                FtileLane::in($this->diamond2),
                static function (float $t1, float $t2) use ($x1, $y1, $x2, $y2, $out, $label): array {
                    $middle = ($y1 + $y2) / 2.0;

                    return [
                        Snake::through($out, null, [[$x1 + $t1, $y1], [$x1 + $t1, $middle], [$x2 + $t2, $middle]]),
                        Snake::through($out, Snake::arrow('D'), [[$x2 + $t2, $middle], [$x2 + $t2, $y2]])->withLabel($label),
                    ];
                },
            );
        }
    }

    /**
     * The arrow into its steps: down, across halfway when they do not stand under it.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private function inSnake(array $from, array $to): Snake
    {
        [$x1, $y1] = $from;
        [$x2, $y2] = $to;
        $points = $x1 === $x2 ? [[$x1, $y1], [$x2, $y2]] : [[$x1, $y1], [$x1, ($y1 + $y2) / 2], [$x2, ($y1 + $y2) / 2], [$x2, $y2]];

        return Snake::through($this->in, Snake::arrow('D'), $points)->withLabel($this->inLabel);
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

    /** As PlantUML when its swimlane is left of its steps': from the test's diamond's left corner up past its left into the first diamond's. */
    private function drawBackLeft(Canvas $canvas): void
    {
        [$x1, $y1] = $this->at($this->diamond2, [0.0, $this->diamond2->geometry()->height / 2]);
        [$x2, $y2] = $this->at($this->diamond1, [0.0, $this->diamond1->geometry()->height / 2]);
        $canvas->snake(
            Snake::through($this->incoming1, Snake::arrow('R'), [[$x1, $y1], [-self::HALF, $y1], [-self::HALF, $y2], [$x2, $y2]])
                ->emphasizing('U')
                ->withLabel($this->back1),
        );
    }

    /** As PlantUML when its test stands in another swimlane: from the test's diamond back across into the first diamond. */
    private function drawBackAcross(Canvas $canvas): void
    {
        [$ax, $ay] = $this->translateFor($this->diamond2);
        [$bx, $by] = $this->translateFor($this->diamond1);
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();
        $repeat = $this->block->geometry()->width;
        $color = $this->incoming1;
        $across = static function (float $t1, float $t2) use ($ax, $ay, $bx, $by, $diamond1, $diamond2, $repeat, $color): array {
            [$x1, $y1] = [$ax + $t1, $ay + ($diamond2->height / 2)];
            [$x2, $y2] = [$bx + $t2 + $diamond1->width, $by + ($diamond1->height / 2)];
            $x1a = $x1 + $diamond2->width;
            $x1b = $x1 + ($diamond2->width / 2) + ($repeat / 2) + self::HALF;
            if ($x2 < $x1a) {
                $turn = $x1a < $x1b ? $x1b : $x1a + 10;

                return [Snake::through($color, Snake::arrow('L'), [[$x1a, $y1], [$turn, $y1], [$turn, $y2], [$x2, $y2]])->emphasizing('U')];
            }
            $x2 = $bx + $t2;
            $middle = ($x1a / 4) + (($x2 * 3) / 4);

            return [Snake::through($color, Snake::arrow('R'), [[$x1a, $y1], [$middle, $y1], [$middle, $y2], [$x2, $y2]])->emphasizing('U')];
        };
        $canvas->link($across(t1: 0.0, t2: 0.0)[0], FtileLane::in($this->diamond2), null, $across);
    }

    /** From the test's diamond into the backward action, and from it into the first diamond — across swimlanes as PlantUML bends them. */
    private function drawBackward(Canvas $canvas, Ftile $backward): void
    {
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();
        $geometry = $backward->geometry();
        [$x, $y] = $this->translateFor($this->diamond2);
        [$bx, $by] = $this->at($backward, [$geometry->left, (float) $geometry->outY]);
        $incoming1 = $this->incoming1;
        $back1 = $this->back1;
        $first = static function (float $t1, float $t2) use ($x, $y, $bx, $by, $diamond2, $incoming1, $back1): array {
            $x2 = $bx + $t2;
            $x1 = $x2 < ($x + $t1 + ($diamond2->width / 2)) ? $x + $t1 : $x + $t1 + $diamond2->width;
            $y1 = $y + ($diamond2->height / 2);

            return [Snake::through($incoming1, Snake::arrow('U'), [[$x1, $y1], [$x2, $y1], [$x2, $by]])->withLabel($back1)];
        };
        $canvas->link($first(t1: 0.0, t2: 0.0)[0], FtileLane::in($this->diamond2), FtileLane::in($backward), $first);
        [$x1, $y1] = $this->at($backward, [$geometry->left, $geometry->inY]);
        [$x2, $y2] = $this->diamond1Right();
        [$dx] = $this->translateFor($this->diamond1);
        $incoming2 = $this->incoming2;
        $back2 = $this->back2;
        $canvas->link(
            Snake::through($incoming2, Snake::arrow('L'), [[$x1, $y1], [$x1, $y2], [$x2, $y2]])->withLabel($back2),
            FtileLane::in($backward),
            null,
            static function (float $t1, float $t2) use ($x1, $y1, $y2, $dx, $diamond1, $incoming2, $back2): array {
                $ax = $x1 + $t1;
                $bx = $dx + $t2;
                $bx += $bx < $ax ? $diamond1->width : 0.0;

                return [Snake::through($incoming2, Snake::arrow($bx < $ax ? 'L' : 'R'), [[$ax, $y1], [$ax, $y2], [$bx, $y2]])->withLabel($back2)];
            },
        );
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
