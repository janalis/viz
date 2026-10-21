<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Source\Unsupported;

/**
 * A `switch`, as PlantUML lays it out: its test's diamond on top, its cases side by side 20
 * apart under it — spread under the diamond when it is wider than the cases between the first
 * and the last — and an empty diamond below them where the cases that go on meet.
 */
final class FtileSwitch extends AbstractFtile
{
    private const float SEPARATION = 20.0;
    private const float SUPP15 = 15.0;

    /** Whether the diamond is wider than the cases between the first and the last. */
    public readonly bool $big;
    /** The diamond's width beyond the first case's right and the last case's left. */
    private readonly float $w13;
    /** The width of the cases between the first and the last. */
    private readonly float $w9;

    /**
     * @param non-empty-list<Ftile> $tiles each case, room made for its labels in and out
     * @param list<?TextBlock> $positives each case's label on the arrow into it
     */
    public function __construct(
        public readonly array $tiles,
        public readonly FtileDiamond $diamond1,
        public readonly FtileDiamond $diamond2,
        private readonly array $positives,
    ) {
        $last = $tiles[\count($tiles) - 1]->geometry();
        $first = $tiles[0]->geometry();
        $this->w13 = $diamond1->geometry()->width - ($first->width - $first->left) - $last->left;
        $w9 = 0.0;
        for ($i = 1; $i < (\count($tiles) - 1); ++$i) {
            $w9 += $tiles[$i]->geometry()->width;
        }
        $this->w9 = $w9;
        $this->big = $this->w13 > $w9;
    }

    #[\Override]
    protected function measure(): Geometry
    {
        $internal = $this->internal();
        foreach ($this->tiles as $tile) {
            if ($tile->geometry()->hasOut()) {
                return $internal;
            }
        }

        return $internal->withoutOut();
    }

    #[\Override]
    public function children(): array
    {
        return [...$this->tiles, $this->diamond1, $this->diamond2];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return match ($child) {
            $this->diamond1 => $this->diamond1At(),
            $this->diamond2 => $this->diamond2At(),
            default => $this->tileAt((int) \array_search($child, $this->tiles, strict: true)),
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $this->diamond1->draw($canvas->moved(...$this->diamond1At()));
        /** @var list<string> $lanes */
        $lanes = $this->big ? \array_values(\array_unique([...(null === $canvas->lane() ? [] : [$canvas->lane()]), ...FtileLane::all($this)])) : [];
        foreach ($this->tiles as $index => $tile) {
            $tile->draw($canvas->moved(...$this->tileAt($index)));
            $step = self::single($tile);
            if (null === $step || $step->lane === $canvas->lane() || [] === $lanes) {
                continue;
            }
            // As PlantUML, which draws the cases of a wide test past its swimlanes' filter: a case of a single step again in each other swimlane of the switch.
            if (\count(FtileLane::all($step)) > 1) {
                throw new Unsupported('The native PlantUML renderer does not draw a wide switch holding steps of other swimlanes');
            }
            foreach (\array_diff($lanes, [$step->lane]) as $lane) {
                $tile->draw($canvas->pinned($lane)->moved(...$this->tileAt($index)));
            }
        }
        if ($this->geometry()->hasOut()) {
            $this->diamond2->draw($canvas->moved(...$this->diamond2At()));
        }
    }

    /** The step a case holds, when it holds a single one. */
    private static function single(Ftile $tile): ?FtileLane
    {
        $children = $tile->children();

        return match (true) {
            $tile instanceof FtileLane => $tile,
            1 === \count($children) => self::single($children[0]),
            default => null,
        };
    }

    /** @return array{float, float} where a case stands */
    public function tileAt(int $index): array
    {
        $dy = $this->diamond1->geometry()->height + $this->ydelta1a();
        $dx = 0.0;
        if (!$this->big) {
            for ($i = 0; $i < $index; ++$i) {
                $dx += $this->tiles[$i]->geometry()->width + self::SEPARATION;
            }

            return [$dx, $dy];
        }
        $last = \count($this->tiles) - 1;
        if ($index === $last) {
            return [$this->tiles[0]->geometry()->width + $this->w13 + (2 * self::SUPP15), $dy];
        }
        $spread = ($this->w13 - $this->w9) / $last;
        for ($i = 0; $i < $index; ++$i) {
            $dx += $this->tiles[$i]->geometry()->width + $spread;
        }

        return [$dx, $dy];
    }

    /** @return array{float, float} where the test's diamond stands */
    public function diamond1At(): array
    {
        return [$this->internal()->left - $this->diamond1->geometry()->left, 0.0];
    }

    /** @return array{float, float} where the diamond the cases meet at stands */
    public function diamond2At(): array
    {
        $internal = $this->internal();
        $diamond = $this->diamond2->geometry();

        return [$internal->left - ($diamond->width / 2), $internal->height - $diamond->height];
    }

    /** How far below the test's diamond the cases start: room for their labels in, more for the arrows round a big diamond. */
    private function ydelta1a(): float
    {
        if (1 === \count($this->tiles)) {
            return 20.0;
        }
        $max = 10.0;
        foreach ($this->positives as $label) {
            $max = \max($max, $label?->size()[1] ?? 0.0);
        }

        return $max + ($this->big ? $this->diamond1->geometry()->height / 2 : 0.0) + 10.0;
    }

    private function internal(): Geometry
    {
        $diamond1 = $this->diamond1->geometry();
        $diamond2 = $this->diamond2->geometry();
        $width = 0.0;
        $height = 0.0;
        foreach ($this->tiles as $tile) {
            $width += $tile->geometry()->width;
            $height = \max($height, $tile->geometry()->height);
        }
        $width += self::SEPARATION * (\count($this->tiles) - 1);
        $height += 100.0;
        $deltas = $this->ydelta1a() + 10.0;
        if ($this->big) {
            $total = $diamond1->height + $height + $diamond2->height + $deltas;
            $first = $this->tiles[0]->geometry();
            $wide = $first->width + self::SUPP15 + $this->w13 + self::SUPP15 + $this->tiles[\count($this->tiles) - 1]->geometry()->width;

            return new Geometry($wide, $total, $first->left + self::SUPP15 + $diamond1->left, 0.0, $total);
        }

        return $diamond1
            ->appendBottom(new Geometry($width, $height, $width / 2, 0.0))
            ->appendBottom($diamond2)
            ->addDim(0.0, $deltas);
    }
}
