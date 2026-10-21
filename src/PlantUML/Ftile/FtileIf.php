<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Source\Unsupported;

/**
 * A decision with both its branches side by side, as PlantUML lays it out: its test's diamond
 * on top, a branch either side of it — arrows from the diamond's corners down into them — and
 * the arrows out of them into the diamond closing it, or straight down when only one leaves.
 * A first note either side stands beside the diamond, the branches lower for it.
 */
final class FtileIf extends AbstractFtile
{
    /** How much wider than its diamond the room between the branches is at least. */
    private const float SUPP_WIDTH = 20.0;

    private float $xDeltaNote = 0.0;
    private float $yDeltaNote = 0.0;
    private float $suppWidthNode = 0.0;

    public function __construct(
        private readonly FtileDiamond $diamond1,
        private readonly Ftile $tile1,
        private readonly Ftile $tile2,
        private readonly Ftile $diamond2,
        private readonly BranchTile $branch1,
        private readonly BranchTile $branch2,
        private readonly Rainbow $arrow,
        private readonly ?Opale $left = null,
        private readonly ?Opale $right = null,
        private readonly ConditionEnd $end = ConditionEnd::Diamond,
        /** How many swimlanes it stands in, which moves its branches further from its diamonds. */
        private readonly int $lanes = 1,
    ) {
        if (null !== $left) {
            [$width, $height] = $left->size();
            $this->xDeltaNote = \max(0.0, $width - $this->diamond1At()[0]);
            $this->yDeltaNote = $height;
        }
        if (null !== $right) {
            [$width, $height] = $right->size();
            $reach = $this->diamond1At()[0] + $diamond1->geometry()->width + $width;
            $this->suppWidthNode = \max(0.0, $reach - $this->internal()->width);
            $this->yDeltaNote = \max($this->yDeltaNote, $height);
        }
    }

    /** How much room its first branch's label needs on its left. */
    public function marginForLabel1(TextBlock $label): float
    {
        return \max(0.0, $label->size()[0] - $this->diamond1At()[0]);
    }

    /** How much room its second branch's label needs on its right. */
    public function marginForLabel2(TextBlock $label): float
    {
        return \max(0.0, $this->diamond1At()[0] + $this->diamond1->geometry()->width + $label->size()[0] - $this->geometry()->width);
    }

    /** How much room its branches' labels need above it. */
    public function marginForLabels(TextBlock $label1, TextBlock $label2): float
    {
        return \max(0.0, \max($label1->size()[1], $label2->size()[1]) - $this->diamond1->geometry()->height);
    }

    #[\Override]
    protected function measure(): Geometry
    {
        $internal = $this->internal();

        return $this->tile1->geometry()->hasOut() || $this->tile2->geometry()->hasOut() ? $internal : $internal->withoutOut();
    }

    #[\Override]
    public function children(): array
    {
        return [$this->diamond1, $this->diamond2, $this->tile1, $this->tile2];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return match ($child) {
            $this->tile1 => $this->branch1At(),
            $this->tile2 => $this->branch2At(),
            $this->diamond1 => $this->diamond1At(),
            default => $this->diamond2At(),
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        [$x] = $this->diamond1At();
        if (null !== $this->left) {
            $canvas->add(...$this->left->shapes($x - $this->left->size()[0], 0.0));
        }
        if (null !== $this->right) {
            $canvas->add(...$this->right->shapes($x + $this->diamond1->geometry()->width, 0.0));
        }
        foreach ([$this->diamond1, $this->tile1, $this->tile2, $this->diamond2] as $tile) {
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->spanning()->moved($dx, $dy));
        }
        $this->drawIn($canvas, $this->tile1, $this->branch1);
        $this->drawIn($canvas, $this->tile2, $this->branch2);
        $out1 = $this->tile1->geometry()->hasOut();
        $out2 = $this->tile2->geometry()->hasOut();
        if ($out1 && $out2 && ConditionEnd::Hline === $this->end && $this->lanes > 1) {
            throw new Unsupported('The native PlantUML renderer does not draw a decision closed by a line across swimlanes');
        }
        if ($out1 && $out2 && ConditionEnd::Hline === $this->end) {
            $this->drawHline($canvas);

            return;
        }
        if ($out1 && $out2) {
            $this->drawOut($canvas, $this->tile1, $this->branch1);
            $this->drawOut($canvas, $this->tile2, $this->branch2);

            return;
        }
        if ($out1) {
            $this->drawOutDirect($canvas, $this->tile1, $this->branch1);
        }
        if ($out2) {
            $this->drawOutDirect($canvas, $this->tile2, $this->branch2);
        }
    }

    /** From the diamond's corner across, then down into a branch — round the diamond when the branch's swimlane turns it the other way. */
    private function drawIn(Canvas $canvas, Ftile $tile, BranchTile $branch): void
    {
        $diamond = $this->diamond1->geometry();
        [$dx, $dy] = $this->diamond1At();
        [$x1, $y1] = $tile === $this->tile1 ? $diamond->pointD() : $diamond->pointB();
        [$x1, $y1] = [$x1 + $dx, $y1 + $dy];
        [$tx, $ty] = $this->translateFor($tile);
        [$x2, $y2] = $tile->geometry()->in();
        [$x2, $y2] = [$x2 + $tx, $y2 + $ty];
        $head = $branch->empty() ? null : Snake::arrow('D');
        $color = $branch->inColor($this->arrow);
        $height = $diamond->height;
        $canvas->link(
            Snake::through($color, $head, [[$x1, $y1], [$x2, $y1], [$x2, $y2]]),
            null,
            FtileLane::in($tile),
            static function (float $t1, float $t2) use ($x1, $y1, $x2, $y2, $color, $head, $height): array {
                [$ax, $ay] = [$x1 + $t1, $y1];
                $bx = $x2 + $t2;
                $parts = [];
                if (self::leftward($x1, $x2) !== self::leftward($ax, $bx)) {
                    $delta = ($x1 > $x2 ? -1 : 1) * FtileDiamond::HALF;
                    $parts[] = Snake::through($color, null, [[$ax, $ay], [$ax + $delta, $ay], [$ax + $delta, $ay + ($height * 0.75)]]);
                    [$ax, $ay] = [$ax + $delta, $ay + ($height * 0.75)];
                }
                $parts[] = Snake::through($color, $head, [[$ax, $ay], [$bx, $ay], [$bx, $y2]])->withMerge(MergeStrategy::Limited);

                return $parts;
            },
        );
    }

    /** From a branch down, then across into the closing diamond's corner — across halfway down when the branch stands in another swimlane. */
    private function drawOut(Canvas $canvas, Ftile $tile, BranchTile $branch): void
    {
        [$x1, $y1] = $this->outOf($tile);
        $diamond = $this->diamond2->geometry();
        [$dx, $dy] = $this->diamond2At();
        [$x2, $y2] = $tile === $this->tile1 ? $diamond->pointD() : $diamond->pointB();
        $x2 += $dx;
        $y2 += $dy;
        $color = $branch->out() ?? $this->arrow;
        $head = Snake::arrow($x2 > $x1 ? 'R' : 'L');
        $snake = Snake::through($color, $head, [[$x1, $y1], [$x1, $y2], [$x2, $y2]]);
        $canvas->link($branch->empty() ? $snake->emphasizing('D') : $snake, FtileLane::out($tile), null, static function (float $t1, float $t2) use ($x1, $y1, $x2, $y2, $color, $head): array {
            [$ax, $bx] = [$x1 + $t1, $x2 + $t2];
            $delta = ($x2 > $x1 ? -1 : 1) * 1.5 * FtileDiamond::HALF;
            if (self::leftward($x1, $x2) === self::leftward($ax, $bx)) {
                $middle = ($y1 + $y2) / 2.0;

                return [
                    Snake::through($color, null, [[$ax, $y1], [$ax, $middle], [$bx + $delta, $middle], [$bx + $delta, $y2]])->withMerge(MergeStrategy::Limited),
                    Snake::through($color, $head, [[$bx + $delta, $y2], [$bx, $y2]])->withMerge(MergeStrategy::Limited),
                ];
            }
            $above = $y2 - (1.5 * FtileDiamond::HALF);

            return [
                Snake::through($color, null, [[$ax, $y1], [$ax, $above], [$bx + $delta, $above]])->withMerge(MergeStrategy::Limited),
                Snake::through($color, $head, [[$bx + $delta, $above], [$bx + $delta, $y2], [$bx, $y2]])->withMerge(MergeStrategy::Limited),
            ];
        });
    }

    /**
     * As PlantUML's `leftOrRight`, whether one point stands left of another, which PlantUML fails on when neither does.
     *
     * @throws Unsupported for points one above the other
     */
    private static function leftward(float $x1, float $x2): bool
    {
        return $x1 === $x2 ? throw new Unsupported('The native PlantUML renderer does not draw an arrow PlantUML fails to bend across swimlanes') : $x1 < $x2;
    }

    /** Each branch straight down to its bottom, and a line across between them and its middle, as PlantUML closes it on a line. */
    private function drawHline(Canvas $canvas): void
    {
        $internal = $this->internal();
        $xs = [$internal->width / 2];
        foreach ([$this->tile1, $this->tile2] as $tile) {
            [$x, $y] = $this->outOf($tile);
            $xs[] = $x;
            $canvas->snake(Snake::through($this->arrow, Snake::arrow('D'), [[$x, $y], [$x, $internal->height]]));
        }
        $canvas->snake(Snake::through($this->arrow, null, [[\min($xs), $internal->height], [\max($xs), $internal->height]])->withMerge(MergeStrategy::None));
    }

    /** From the only branch leaving down to the decision's way out — to just above it, across swimlanes. */
    private function drawOutDirect(Canvas $canvas, Ftile $tile, BranchTile $branch): void
    {
        [$x1, $y1] = $this->outOf($tile);
        $internal = $this->internal();
        $color = $branch->out() ?? $this->arrow;
        $snake = Snake::through($color, null, [[$x1, $y1], [$x1, $internal->height], [$internal->left, $internal->height]]);
        $canvas->link($branch->empty() ? $snake->emphasizing('D') : $snake, FtileLane::out($tile), null, static function (float $t1, float $t2) use ($x1, $y1, $internal, $color): array {
            [$ax, $bx, $by] = [$x1 + $t1, $internal->left + $t2, $internal->height - FtileDiamond::HALF];

            return [Snake::through($color, null, [[$ax, $y1], [$ax, $by], [$bx, $by], [$bx, $internal->height]])->withMerge(MergeStrategy::Limited)];
        });
    }

    /** @return array{float, float} */
    private function outOf(Ftile $tile): array
    {
        [$dx, $dy] = $this->translateFor($tile);
        [$x, $y] = $tile->geometry()->out();

        return [$x + $dx, $y + $dy];
    }

    /** Its size with its way out, as if a branch left. */
    private function internal(): Geometry
    {
        $dim1 = $this->tile1->geometry();
        $dim2 = $this->tile2->geometry();
        $inner = \max($dim1->width - $dim1->left + $dim2->left, $this->diamond1->geometry()->width + self::SUPP_WIDTH);
        $width = $this->xDeltaNote + $dim1->left + $inner + $dim2->width - $dim2->left + $this->suppWidthNode;
        $height = $this->yDeltaNote + \max($dim1->height, $dim2->height);
        $nude = new Geometry($width, $height, $this->xDeltaNote + $dim1->left + ($inner / 2), $this->yDeltaNote, $height);
        $all = $this->diamond1->geometry()->appendBottom($nude)->appendBottom($this->diamond2->geometry());
        $delta = $this->ydelta1a() + $this->ydelta1b($dim1->hasOut() && $dim2->hasOut()) + ($this->diamond2 instanceof FtileDiamond ? $this->diamond2->sideLabelHeight() : 0.0);

        return $all->addDim(0.0, $delta)->incInY($this->yDeltaNote);
    }

    /** @return array{float, float} */
    private function branch1At(): array
    {
        return [$this->xDeltaNote, $this->yDeltaNote + $this->diamond1->geometry()->height + $this->ydelta1a()];
    }

    /** @return array{float, float} */
    private function branch2At(): array
    {
        return [$this->internal()->width - $this->tile2->geometry()->width - $this->suppWidthNode, $this->yDeltaNote + $this->diamond1->geometry()->height + $this->ydelta1a()];
    }

    /** How far under the test the branches start: further across swimlanes. */
    private function ydelta1a(): float
    {
        return $this->lanes > 1 ? 20.0 : 10.0;
    }

    /** How far over the closing diamond the branches end: across swimlanes, or when both leave. */
    private function ydelta1b(bool $both): float
    {
        return match (true) {
            $this->lanes > 1 => 10.0,
            $both => 6.0,
            default => 0.0,
        };
    }

    /** @return array{float, float} */
    private function diamond1At(): array
    {
        return [$this->internal()->left - $this->diamond1->geometry()->left, $this->yDeltaNote];
    }

    /** @return array{float, float} */
    private function diamond2At(): array
    {
        $internal = $this->internal();
        $diamond = $this->diamond2->geometry();

        return [$internal->left - ($diamond->width / 2), $internal->height - $diamond->height];
    }
}
