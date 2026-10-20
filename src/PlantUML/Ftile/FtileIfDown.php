<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A decision with one branch of steps, as PlantUML lays it out: its test's diamond, the branch
 * under it, then the diamond closing it, the other way round the branch on the right — or,
 * when that way only stops, an arrow across to its stop. A single note stands left of the test.
 */
final class FtileIfDown extends AbstractFtile
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
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->moved($dx, $dy));
        }
        $then = $this->then->geometry();
        [$x1, $y1] = $this->at($this->diamond1, $this->diamond1->geometry()->out());
        [$x2, $y2] = $this->at($this->then, $then->in());
        $canvas->snake(Snake::through($this->arrow->over($this->then->inLink()->color), Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]]));
        $this->drawElse($canvas);
        if ($then->hasOut()) {
            [$x1, $y1] = $this->at($this->then, $then->out());
            [$x2, $y2] = $this->at($this->diamond2, $this->diamond2->geometry()->in());
            $canvas->snake(Snake::through($this->arrow->over($this->then->outLink()->color), Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]]));
        }
    }

    /** The other way: across to the stop, or round the branch's right to the closing diamond or the way out. */
    private function drawElse(Canvas $canvas): void
    {
        [$x1, $y1] = $this->at($this->diamond1, self::middleRight($this->diamond1->geometry()));
        if (null !== $this->stop) {
            [$x2, $y2] = $this->at($this->stop, [0.0, $this->stop->geometry()->height / 2]);
            $canvas->snake(Snake::through($this->elseColor, Snake::arrow('R'), [[$x1, $y1], [$x2, $y2]]));

            return;
        }
        [$x2, $y2] = $this->then->geometry()->hasOut()
            ? $this->at($this->diamond2, self::middleRight($this->diamond2->geometry()))
            : $this->geometry()->out();
        $xmax = \max($x1 + self::HALF, $this->thenAt()[0] + $this->then->geometry()->width);
        $canvas->add(Shape::empty($x2, $y2 - self::HALF, 5.0, self::HALF));
        $canvas->snake(Snake::through($this->elseColor, Snake::arrow('L'), [[$x1, $y1], [$xmax, $y1], [$xmax, $y2], [$x2, $y2]])->emphasizing('D'));
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
