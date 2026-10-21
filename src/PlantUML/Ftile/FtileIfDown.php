<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A decision with one branch of steps, as PlantUML lays it out: its test's diamond, the branch
 * under it, then the diamond closing it, the other way round the branch on the right — or,
 * when that way only stops, an arrow across to its stop. A single note stands left of the test.
 */
final class FtileIfDown extends AbstractFtile implements LaneExit
{
    private const float HALF = FtileDiamond::HALF;

    private readonly Ftile $diamond2;

    public function __construct(
        private readonly FtileDiamond $diamond1,
        Ftile $diamond2,
        private readonly Ftile $then,
        private readonly Rainbow $arrow,
        /** The colour of the other way round, to the closing diamond or to the stop. */
        private readonly Rainbow $elseColor,
        /** The other way when it only stops, put right of the test. */
        private readonly ?Ftile $stop = null,
        private readonly ?Opale $note = null,
        private readonly ConditionEnd $end = ConditionEnd::Diamond,
        /** Whether the other way goes round the branch's left, as when the test's swimlane is left of the branch's. */
        private readonly bool $left = false,
    ) {
        $this->diamond2 = null === $stop ? $diamond2 : new FtileEmpty();
    }

    #[\Override]
    protected function measure(): Geometry
    {
        [$noteWidth, $noteHeight] = $this->note?->size() ?? [0.0, 0.0];
        $diamond1 = $this->diamond1->geometry();
        $then = $this->then->geometry();
        $geometry = $diamond1->appendBottom($then)->appendBottom($this->diamond2->geometry());
        $height = $geometry->height + (3 * self::HALF) + \max(self::HALF, $this->diamond1->south->size()[1]) + $noteHeight;
        $supp = \max(0.0, $noteWidth - $geometry->left);
        $width = $supp + $geometry->width + self::HALF + (null === $this->stop ? 0.0 : $this->stop->geometry()->width + $this->additionalWidth());
        $left = $supp > 0 ? $noteWidth + $diamond1->left : $geometry->left;
        $result = new Geometry($width, $height, $left, $diamond1->inY + $noteHeight, $height);

        return !$then->hasOut() && null !== $this->stop ? $result->withoutOut() : $result;
    }

    /** As PlantUML, once the other way stops, the flow leaves in the branch's swimlane. */
    #[\Override]
    public function exitLane(): ?string
    {
        return null === $this->stop ? null : FtileLane::out($this->then);
    }

    /** As PlantUML, a stop in a swimlane neither the test's nor the branch's is drawn in none. */
    private function drawsStop(?string $lane): bool
    {
        $stop = null === $this->stop ? null : FtileLane::in($this->stop);

        return null === $stop || $stop === $lane || \in_array($stop, FtileLane::all($this->then), strict: true);
    }

    #[\Override]
    public function children(): array
    {
        return [$this->then, $this->diamond1, $this->diamond2, ...(null === $this->stop ? [] : [$this->stop])];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return match ($child) {
            $this->then => $this->thenAt(),
            $this->diamond1 => $this->diamond1At(),
            $this->diamond2 => $this->diamond2At(),
            default => $this->stopAt(),
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $note = $this->note;
        if (null !== $note) {
            $canvas->add(...$note->shapes($this->diamond1At()[0] - $note->size()[0], 0.0));
        }
        foreach ($this->children() as $tile) {
            if ($tile === $this->stop && !$this->drawsStop($canvas->lane())) {
                continue;
            }
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->spanning()->moved($dx, $dy));
        }
        $then = $this->then->geometry();
        $p1 = $this->at($this->diamond1, $this->diamond1->geometry()->out());
        $p2 = $this->at($this->then, $then->in());
        self::down($canvas, Snake::through($this->arrow->over($this->then->inLink()->color), Snake::arrow('D'), [$p1, $p2]), null, FtileLane::in($this->then), $p2);
        $this->drawElse($canvas);
        if ($then->hasOut()) {
            $p1 = $this->at($this->then, $then->out());
            $p2 = $this->at($this->diamond2, $this->diamond2->geometry()->in());
            $end = ConditionEnd::Hline === $this->end ? $this->at($this->diamond2, self::middleRight($this->diamond2->geometry())) : $p2;
            // As PlantUML, the room closing it when the other way stops stands in no swimlane: the arrow stays in the branch's.
            $to = null === $this->stop ? null : FtileLane::out($this->then);
            self::down($canvas, Snake::through($this->arrow->over($this->then->outLink()->color), Snake::arrow('D'), [$p1, $end]), FtileLane::out($this->then), $to, $p2);
        }
    }

    /**
     * An arrow down between the test and the branch, across swimlanes down to halfway, across, then down to where it ends there.
     *
     * @param array{float, float} $end
     */
    private static function down(Canvas $canvas, Snake $snake, ?string $from, ?string $to, array $end): void
    {
        $canvas->link($snake, $from, $to, static function (float $t1, float $t2) use ($snake, $end): array {
            [$x1, $y1] = $snake->worm->first();
            [$x2, $y2] = [$end[0] + $t2, $end[1]];
            $middle = ($y1 + $y2) / 2.0;

            return [$snake->along([[$x1 + $t1, $y1], [$x1 + $t1, $middle], [$x2, $middle], [$x2, $y2]])];
        });
    }

    /** The other way: across to the stop, or round the branch's right to the closing diamond or the way out. */
    private function drawElse(Canvas $canvas): void
    {
        [$x1, $y1] = $this->at($this->diamond1, self::middleRight($this->diamond1->geometry()));
        $stop = $this->stop;
        if (null !== $stop) {
            [$x2, $y2] = $this->at($stop, [0.0, $stop->geometry()->height / 2]);
            // As PlantUML, the arrow to a stop in another swimlane is not drawn.
            $canvas->link(
                Snake::through($this->elseColor, Snake::arrow('R'), [[$x1, $y1], [$x2, $y2]]),
                null,
                FtileLane::in($stop),
                static fn (): array => [],
            );

            return;
        }
        if ($this->left && $this->then->geometry()->hasOut() && ConditionEnd::Diamond === $this->end) {
            $this->drawElseLeft($canvas);

            return;
        }
        [$x2, $y2] = $this->then->geometry()->hasOut()
            ? $this->at($this->diamond2, self::middleRight($this->diamond2->geometry()))
            : $this->geometry()->out();
        $xmax = \max($x1 + self::HALF, $this->thenAt()[0] + $this->then->geometry()->width);
        if (ConditionEnd::Hline === $this->end && $this->then->geometry()->hasOut()) {
            // As PlantUML: down beside the branch, then a line across under it and down to the way out.
            [, $y3] = $this->at($this->diamond2, $this->diamond2->geometry()->out());
            $canvas->add(Shape::empty($xmax, $y2 - self::HALF, 5.0, self::HALF));
            $canvas->snake(Snake::through($this->elseColor, Snake::arrow('D'), [[$x1, $y1], [$xmax, $y1], [$xmax, $y2]]));
            $canvas->snake(Snake::through($this->elseColor, null, [[$xmax, $y2], [$x2, $y2], [$x2, $y3]])->withMerge(MergeStrategy::None));

            return;
        }
        $canvas->add(Shape::empty($x2, $y2 - self::HALF, 5.0, self::HALF));
        $canvas->snake(Snake::through($this->elseColor, Snake::arrow('L'), [[$x1, $y1], [$xmax, $y1], [$xmax, $y2], [$x2, $y2]])->emphasizing('D'));
    }

    /** The other way round the branch's left, from the test's left corner to the closing diamond's. */
    private function drawElseLeft(Canvas $canvas): void
    {
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();
        [$x1, $y1] = $this->at($this->diamond1, [0.0, $diamond1->inY + (((float) $diamond1->outY - $diamond1->inY) / 2)]);
        [$x2, $y2] = $this->at($this->diamond2, [0.0, $diamond2->inY + (((float) $diamond2->outY - $diamond2->inY) / 2)]);
        $xmin = \min($x1 - self::HALF, $this->thenAt()[0]);
        $canvas->add(Shape::empty($x2, $y2 - self::HALF, 5.0, self::HALF));
        $canvas->snake(Snake::through($this->elseColor, Snake::arrow('R'), [[$x1, $y1], [$xmin, $y1], [$xmin, $y2], [$x2, $y2]])->emphasizing('D'));
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
    private static function middleRight(Geometry $geometry): array
    {
        return [$geometry->width, $geometry->inY + (((float) $geometry->outY - $geometry->inY) / 2)];
    }

    private function additionalWidth(): float
    {
        $stop = (float) $this->stop?->geometry()->width;

        return \max($stop, $this->diamond1->east->size()[0] + ($stop / 2));
    }

    /** @return array{float, float} */
    private function thenAt(): array
    {
        $total = $this->geometry();
        $noteHeight = $this->note?->size()[1] ?? 0.0;
        $diamond1 = $this->diamond1->geometry()->height;
        $then = $this->then->geometry();

        return [$total->left - $then->left, $noteHeight + $diamond1 + (($total->height - $noteHeight - $diamond1 - $this->diamond2->geometry()->height - $then->height) / 2)];
    }

    /** @return array{float, float} */
    private function diamond1At(): array
    {
        return [$this->geometry()->left - $this->diamond1->geometry()->left, $this->note?->size()[1] ?? 0.0];
    }

    /** @return array{float, float} */
    private function stopAt(): array
    {
        $diamond1 = $this->diamond1->geometry();
        $stop = (float) $this->stop?->geometry()->height;
        [$x] = $this->diamond1At();

        return [$x + $diamond1->width + $this->additionalWidth(), $diamond1->inY + (($diamond1->height - $diamond1->inY - $stop) / 2)];
    }

    /** @return array{float, float} */
    private function diamond2At(): array
    {
        $total = $this->geometry();
        $diamond2 = $this->diamond2->geometry();

        return [$total->left - $diamond2->left, $total->height - $diamond2->height];
    }
}
