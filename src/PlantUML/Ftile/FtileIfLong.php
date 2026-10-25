<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A decision with `elseif`s, as PlantUML lays it out across: each test's diamond above its
 * branch, side by side 20 apart, an arrow from each diamond to the next, the `else` branch
 * last on the right, and the arrows out of every branch down to a line across the bottom.
 */
final class FtileIfLong extends AbstractFtile
{
    private const float SEPARATION = 20.0;

    /** @var list<Ftile> each test's diamond above its branch */
    private readonly array $couples;
    /** @var list<Ftile> the diamonds, their ways out lined up */
    private readonly array $diamonds;
    /** @var list<FtileAssembly> each diamond above its branch, before the room for its label in */
    private readonly array $assemblies;
    /** @var list<float> the room for each label in, left of its diamond */
    private readonly array $margins;

    /**
     * @param non-empty-list<FtileDiamond> $diamonds
     * @param list<Ftile> $tiles
     * @param list<BranchTile> $thens
     * @param list<?TextBlock> $outs each branch's label out, written before the next branch
     */
    public function __construct(
        array $diamonds,
        private readonly array $tiles,
        private readonly Ftile $tile2,
        private readonly array $thens,
        private readonly array $outs,
        private readonly BranchTile $else,
        private readonly ?TextBlock $elseOut,
        private readonly Rainbow $arrow,
        private readonly Rainbow $in,
        /** The arrow after `endif`. */
        private readonly Rainbow $after,
        /** @var array<string, int> each swimlane's place from the left */
        private readonly array $order = [],
    ) {
        $maxOutY = \max(\array_map(static fn (FtileDiamond $diamond): float => (float) $diamond->geometry()->outY, $diamonds));
        $aligned = [];
        $assemblies = [];
        $margins = [];
        $couples = [];
        foreach ($diamonds as $i => $diamond) {
            $marged = FtileMarged::vertical($diamond, ($maxOutY - (float) $diamond->geometry()->outY) / 2, 20.0);
            $assembly = new FtileAssembly($marged, $tiles[$i]);
            $margin = $diamond->west->size()[0];
            $aligned[] = $marged;
            $assemblies[] = $assembly;
            $margins[] = $margin;
            $couples[] = FtileMarged::horizontal($assembly, $margin, 0.0);
        }
        $this->diamonds = $aligned;
        $this->assemblies = $assemblies;
        $this->margins = $margins;
        $this->couples = $couples;
    }

    #[\Override]
    protected function measure(): Geometry
    {
        $internal = $this->internal();
        foreach ([...$this->tiles, $this->tile2] as $tile) {
            if ($tile->geometry()->hasOut()) {
                return $internal;
            }
        }

        return $internal->withoutOut();
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
        $index = (int) \array_search($child, $this->tiles, strict: true);

        return $this->inCouple($index, $child);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ($this->couples as $index => $couple) {
            $couple->draw($canvas->spanning()->moved(...$this->coupleAt($index)));
        }
        $this->tile2->draw($canvas->spanning()->moved(...$this->tile2At()));
        $height = $this->internal()->height;
        $outs = 0;
        $none = static fn (): array => [];
        foreach ($this->thens as $index => $branch) {
            $tile = $this->tiles[$index];
            $outs += $branch->tile->geometry()->hasOut() ? 1 : 0;
            [$x1, $y1] = $this->at($this->inCouple($index, $this->diamonds[$index]), $this->diamonds[$index]->geometry()->out());
            [$x2, $y2] = $this->at($this->translateFor($tile), $tile->geometry()->in());
            $color = $branch->inColor($this->arrow);
            $canvas->link(
                Snake::through($color, Snake::arrow('D'), [[$x1, $y1], [$x2, $y2]]),
                null,
                FtileLane::in($tile),
                static fn (float $t1, float $t2): array => [
                    Snake::through($color, Snake::arrow('D'), [[$x1 + $t1, $y1], [$x1 + $t1, $y1 + 4], [$x2 + $t2, $y1 + 4], [$x2 + $t2, $y2]]),
                ],
            );
            if ($tile->geometry()->hasOut()) {
                [$x, $y] = $this->at($this->translateFor($tile), $tile->geometry()->out());
                $lane = FtileLane::out($tile);
                $canvas->link(Snake::through($branch->out() ?? $this->arrow, Snake::arrow('D'), [[$x, $y], [$x, $height]])->withLabel($this->outs[$index]), $lane, $lane, $none);
            }
        }
        foreach ($this->thens as $index => $branch) {
            if (0 === $index) {
                continue;
            }
            [$x1, $y1] = $this->diamondRight($index - 1);
            [$x2, $y2] = $this->diamondLeft($index);
            $canvas->snake(Snake::through($this->arrow->over($branch->branch->inlabel->color), Snake::arrow('R'), [[$x1, $y1], [$x2, $y2]]));
        }
        $this->drawInAndElse($canvas, $outs);
    }

    /** Into the first diamond, from the last into the `else` branch, out of it, and the line across — neither of the `else` branch's across swimlanes, as PlantUML. */
    private function drawInAndElse(Canvas $canvas, int $outs): void
    {
        $internal = $this->internal();
        $none = static fn (): array => [];
        [$x2, $y2] = $this->at($this->inCouple(0, $this->diamonds[0]), $this->diamonds[0]->geometry()->in());
        $canvas->snake(Snake::through($this->in, Snake::arrow('D'), [[$internal->left, 0.0], [$x2, 0.0], [$x2, $y2]]));
        [$x1, $y1] = $this->diamondRight(\count($this->diamonds) - 1);
        $tile2 = $this->tile2->geometry();
        [$x2, $y2] = $this->at($this->tile2At(), $tile2->in());
        $canvas->link(Snake::through($this->else->inColor($this->arrow), Snake::arrow('D'), [[$x1, $y1], [$x2, $y1], [$x2, $y2]]), null, FtileLane::in($this->tile2), $none);
        if ($tile2->hasOut()) {
            [$x, $y] = $this->at($this->tile2At(), $tile2->out());
            $points = [[$x, $y], [$x, $internal->height], ...(0 === $outs ? [[$internal->left, $internal->height]] : [])];
            $lane = FtileLane::out($this->tile2);
            $canvas->link(Snake::through($this->else->out() ?? $this->arrow, Snake::arrow('D'), $points)->withLabel($this->elseOut), $lane, $lane, $none);
        }
        if (0 === $outs) {
            return;
        }
        foreach ($canvas->measuring() ? $this->measured($canvas->lane()) : $this->lines($canvas->lane()) as $lane => [$min, $max]) {
            $snake = Snake::through($this->after, null, [[$min, $internal->height], [$max, $internal->height]])->withMerge(MergeStrategy::None);
            '' === $lane ? $canvas->snake($snake) : $canvas->link($snake, $lane, $lane, $none);
        }
    }

    /**
     * The line across under the branches, as PlantUML draws it: from the leftmost way out to the
     * rightmost — or, across swimlanes, in each swimlane between the first and the last a way out
     * leaves in, from its own ways out to the side the others stand on.
     *
     * @return array<string, array{float, float}> each swimlane's stretch, by name, `''` for no swimlane
     */
    private function lines(?string $own): array
    {
        $internal = $this->internal();
        /** @var list<array{?string, float}> $ways each way out's swimlane and place across */
        $ways = [];
        foreach ($this->couples as $index => $couple) {
            if (!$couple->geometry()->hasOut()) {
                continue;
            }
            $ways[] = [FtileLane::out($couple) ?? $own, $this->coupleAt($index)[0] + $couple->geometry()->left];
        }
        if ($this->tile2->geometry()->hasOut()) {
            $ways[] = [FtileLane::out($this->tile2) ?? $own, $this->tile2At()[0] + $this->tile2->geometry()->left];
        }
        $lanes = \array_values(\array_unique([...(null === $own ? [] : [$own]), ...FtileLane::all($this)]));
        if (\count($lanes) < 2) {
            $xs = [$internal->left, ...\array_map(static fn (array $way): float => $way[1], $ways)];

            return ['' => [\min($xs), \max($xs)]];
        }
        $places = \array_map(fn (array $way): int => $this->order[(string) $way[0]] ?? 0, $ways);
        [$first, $last] = [\min($places), \max($places)];
        $lines = [];
        foreach ($lanes as $lane) {
            $place = $this->order[$lane] ?? 0;
            if ($place < $first || $place > $last) {
                continue;
            }
            $xs = [$place !== $first ? 0.0 : $internal->width, $place !== $last ? $internal->width : 0.0, $internal->left];
            foreach ($ways as [$wayLane, $x]) {
                if ($wayLane !== $lane) {
                    continue;
                }
                $xs[] = $x;
            }
            $lines[$lane] = [\min($xs), \max($xs)];
        }

        return $lines;
    }

    /**
     * The line across as PlantUML measures it: in every swimlane the step stands in, from its
     * middle and its way out to every way out, whatever swimlane each leaves in.
     *
     * @return array<string, array{float, float}> each swimlane's stretch, by name, `''` for no swimlane
     */
    private function measured(?string $own): array
    {
        $internal = $this->internal();
        $xs = [$internal->width / 2, $internal->left];
        foreach ($this->couples as $index => $couple) {
            if (!$couple->geometry()->hasOut()) {
                continue;
            }
            $xs[] = $this->coupleAt($index)[0] + $couple->geometry()->left;
        }
        if ($this->tile2->geometry()->hasOut()) {
            $xs[] = $this->tile2At()[0] + $this->tile2->geometry()->left;
        }
        $lanes = \array_values(\array_unique([...(null === $own ? [] : [$own]), ...FtileLane::all($this)]));

        return \array_fill_keys([] === $lanes ? [''] : $lanes, [\min($xs), \max($xs)]);
    }

    /** @return array{float, float} the middle of a diamond's left side */
    private function diamondLeft(int $index): array
    {
        $diamond = $this->diamonds[$index]->geometry();

        return $this->at($this->inCouple($index, $this->diamonds[$index]), [0.0, ($diamond->inY + (float) $diamond->outY) / 2]);
    }

    /** @return array{float, float} the middle of a diamond's right side, twice its way in across */
    private function diamondRight(int $index): array
    {
        [$x, $y] = $this->diamondLeft($index);

        return [$x + ($this->diamonds[$index]->geometry()->left * 2), $y];
    }

    /**
     * Where a couple's diamond or branch stands.
     *
     * @return array{float, float}
     */
    private function inCouple(int $index, Ftile $tile): array
    {
        [$x, $y] = $this->coupleAt($index);
        [$dx, $dy] = $this->assemblies[$index]->translateFor($tile);

        return [$x + $this->margins[$index] + $dx, $y + $dy];
    }

    /**
     * @param array{float, float} $at
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private function at(array $at, array $point): array
    {
        return [$at[0] + $point[0], $at[1] + $point[1]];
    }

    /** @return array{float, float} */
    private function coupleAt(int $index): array
    {
        $x = 0.0;
        for ($i = 0; $i < $index; ++$i) {
            $x += $this->couples[$i]->geometry()->width + self::SEPARATION;
        }

        return [$x, 25.0];
    }

    /** @return array{float, float} */
    private function tile2At(): array
    {
        $internal = $this->internal();
        $tile2 = $this->tile2->geometry();

        return [$internal->width - $tile2->width, ($internal->height - $tile2->height) / 2];
    }

    private function internal(): Geometry
    {
        $width = 0.0;
        $height = 0.0;
        $maxOutY = 0.0;
        $diamonds = 0.0;
        foreach ($this->couples as $index => $couple) {
            $width += $couple->geometry()->width;
            $height = \max($height, $couple->geometry()->height);
            $maxOutY = \max($maxOutY, (float) $this->diamonds[$index]->geometry()->outY);
            $diamonds = \max($diamonds, $this->diamonds[$index]->geometry()->height);
        }
        $tile2 = $this->tile2->geometry();
        $width += $tile2->width + (self::SEPARATION * \count($this->couples));
        $height = \max($height, $tile2->height + ($diamonds / 2)) + \max(100.0, $maxOutY);

        return new Geometry($width, $height, $width / 2, 0.0, $height);
    }
}
