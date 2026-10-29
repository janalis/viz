<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * Flows side by side, as PlantUML's parallel builders lay them out: each 14 clear either side
 * — more for a label on its arrows — centred 20 below a bar and 20 above another, an arrow
 * down from the bar into each and from each down to the other bar: a fork's thick bars, a
 * split's thin lines, or a fork merged into a diamond.
 */
final readonly class ParallelBuilder
{
    private const float BAR = 6.0;

    /** @var non-empty-list<Ftile> */
    private array $tiles;
    /** @var list<?string> the swimlane each flow starts in */
    private array $ins;
    /** @var list<?string> the swimlane each flow leaves in */
    private array $outs;

    /** @param non-empty-list<Ftile> $all */
    public function __construct(
        private FtileFactory $factory,
        array $all,
        /** The swimlane a fork's bar below stands in, where its last `fork again` or its end was written. */
        private ?string $laneOut = null,
    ) {
        $this->ins = \array_map(FtileLane::in(...), $all);
        $this->outs = \array_map(FtileLane::out(...), $all);
        $height = \max(\array_map(static fn (Ftile $tile): float => $tile->geometry()->height, $all));
        $above = \max(\array_map(static fn (Ftile $tile): float => $factory->label($tile->inLink()->display)?->size()[1] ?? 0.0, $all));
        $below = \max(\array_map(static fn (Ftile $tile): float => $factory->label($tile->outLink()->display)?->size()[1] ?? 0.0, $all));
        $tiles = [];
        foreach ($all as $tile) {
            $tile = FtileMarged::horizontal($tile, 14.0, 14.0 + $this->labelReach($tile));
            $tiles[] = FtileMarged::marged($above, FtileMarged::centered($tile, $height + 40), $below);
        }
        $this->tiles = $tiles;
    }

    /** A fork: a bar above its flows, another below them with its label, an arrow into each and out of each that leaves. */
    public function fork(?string $stereogroup, ?string $label): Ftile
    {
        $inner = new FtileForkInner($this->tiles);
        $first = $this->bar($stereogroup);
        $step1 = $this->withArrowsIn($first, $inner, null);
        $last = $this->bar($stereogroup, $this->factory->label(null === $label ? null : \explode('\\n', $label)) ?? new EmptyBlock());
        $last->span($step1->geometry()->width);
        $result = new FtileAssembly($step1, self::laned($last, $this->laneOut));
        $links = [];
        $x = 0.0;
        foreach ($this->tiles as $index => $tile) {
            $geometry = $tile->geometry();
            if ($geometry->hasOut()) {
                $links[] = self::bent(
                    $this->arrowOut($tile, [[$x + $geometry->left, self::BAR + (float) $geometry->outY], [$x + $geometry->left, self::BAR + $inner->geometry()->height]]),
                    $this->outs[$index],
                    $this->laneOut,
                    at: 'end',
                );
            }
            $x += $geometry->width;
        }

        return new FtileSpanning(new FtileDecorate($result, connections: [new Links($links)]));
    }

    /** A fork merged: a bar above its flows, and the arrows out of them into a diamond below. */
    public function merge(?string $stereogroup): Ftile
    {
        $step1 = $this->withArrowsIn($this->bar($stereogroup), new FtileForkInner($this->tiles), $this->ins[0]);
        $style = $this->factory->styles->style([], 'activity');
        $diamond = new FtileDiamond('empty', Pen::of($style), $style->color('BackGroundColor', '#f1f1f1'));
        $last = $this->outs[\count($this->outs) - 1];
        $result = new FtileAssembly($step1, self::laned($diamond, $last));
        [$dx, $dy] = $result->translateFor($diamond);
        $size = $diamond->geometry();
        [$outX, $outY] = $size->out();
        $links = [];
        $x = 0.0;
        foreach ($this->tiles as $index => $tile) {
            $geometry = $tile->geometry();
            if ($geometry->hasOut()) {
                [$x1, $y1] = [$x + $geometry->left, self::BAR + (float) $geometry->outY];
                [$ax, $ay, $head] = match (true) {
                    $x1 < ($outX + $dx - ($size->width / 2)) => [-$size->width / 2, -$size->height / 2, 'R'],
                    $x1 > ($outX + $dx + ($size->width / 2)) => [$size->width / 2, -$size->height / 2, 'L'],
                    default => [0.0, -$size->height, 'D'],
                };
                [$x2, $y2] = [$outX + $dx + $ax, $outY + $dy + $ay];
                // As PlantUML, an arrow into the diamond from another swimlane is not drawn.
                $links[] = [Snake::through($this->factory->arrow->over($tile->outLink()->color), Snake::arrow($head), [
                    [$x1, $y1],
                    [$x1, $y2],
                    [$x2, $y2],
                ]), $this->outs[$index], $last, static fn (): array => []];
            }
            $x += $geometry->width;
        }

        return new FtileSpanning(new FtileDecorate($result, connections: [new Links($links)]));
    }

    /** A split: a thin line above its flows and one below those that leave, or none below when none leaves. */
    public function split(): Ftile
    {
        $inner = new FtileForkInner($this->tiles);
        $visible = [] !== \array_filter($this->tiles, fn (Ftile $tile): bool => 'hidden' !== $this->factory->arrow->over($tile->inLink()->color)->style);
        $first = new FtileBar(thin: true, color: $visible ? $this->factory->arrow->color : null);
        $step1 = $this->withArrowsIn($first, $inner, $this->ins[0], $first);
        if ([] === \array_filter($this->tiles, static fn (Ftile $tile): bool => $tile->geometry()->hasOut())) {
            return new FtileSpanning(new FtileKilled($step1));
        }
        $geometry = $step1->geometry();
        $last = new FtileBar(thin: true, color: $this->factory->arrow->color);
        $lane = $this->outs[\count($this->outs) - 1];
        $links = [];
        $lefts = [];
        $x = 0.0;
        foreach ($this->tiles as $index => $tile) {
            $tileGeometry = $tile->geometry();
            if ($tileGeometry->hasOut()) {
                $lefts[] = $x + $tileGeometry->left;
                $links[] = self::bent(
                    $this->arrowOut($tile, [[$x + $tileGeometry->left, 1.5 + (float) $tileGeometry->outY], [$x + $tileGeometry->left, 1.5 + $inner->geometry()->height]]),
                    $this->outs[$index],
                    $lane,
                    at: 'end',
                );
            }
            $x += $tileGeometry->width;
        }
        $last->span($geometry->width, \min($lefts[0], $geometry->left), \max($lefts[\count($lefts) - 1], $geometry->left));

        return new FtileSpanning(new FtileDecorate(new FtileAssembly($step1, self::laned($last, $lane)), connections: [new Links($links)]));
    }

    /** The bar above the flows, in its swimlane, an arrow down from it into each — a thin line spanning their first and last. */
    private function withArrowsIn(FtileBar $bar, FtileForkInner $inner, ?string $lane, ?FtileBar $thin = null): Ftile
    {
        $links = [];
        $lefts = [];
        $x = 0.0;
        foreach ($this->tiles as $index => $tile) {
            $geometry = $tile->geometry();
            $lefts[] = $x + $geometry->left;
            $snake = Snake::through($this->factory->arrow->over($tile->inLink()->color), Snake::arrow('D'), [[$x + $geometry->left, 0.0], [$x + $geometry->left, $geometry->inY]]);
            $links[] = self::bent($snake->withLabel($this->factory->label($tile->inLink()->display)), $lane, $this->ins[$index], at: 'start');
            $x += $geometry->width;
        }
        $result = new FtileDecorate($inner, connections: [new Links($links)]);
        $geometry = $result->geometry();
        null === $thin ? $bar->span($geometry->width) : $bar->span($geometry->width, \min($lefts[0], $geometry->left), \max($lefts[\count($lefts) - 1], $geometry->left));

        return new FtileAssembly(self::laned($bar, $lane), $result);
    }

    /**
     * An arrow down between a bar and a flow, across swimlanes as PlantUML bends it: across 4 below its `start`, or 14 above its `end`.
     *
     * @return array{Snake, ?string, ?string, \Closure(float, float): list<Snake|Shape>}
     */
    private static function bent(Snake $snake, ?string $from, ?string $to, string $at): array
    {
        return [
            $snake,
            $from,
            $to,
            static function (float $t1, float $t2) use ($snake, $at): array {
                [$x1, $y1] = $snake->worm->first();
                [$x2, $y2] = $snake->worm->last();
                $middle = 'end' === $at ? $y2 - 14 : $y1 + 4;

                return [$snake->along([[$x1 + $t1, $y1], [$x1 + $t1, $middle], [$x2 + $t2, $middle], [$x2 + $t2, $y2]])];
            },
        ];
    }

    /** A tile in a swimlane, or as it is when it has none. */
    private static function laned(Ftile $tile, ?string $lane): Ftile
    {
        return null === $lane ? $tile : new FtileLane($tile, $lane);
    }

    /** @param list<array{float, float}> $points */
    private function arrowOut(Ftile $tile, array $points): Snake
    {
        return Snake::through($this->factory->arrow->over($tile->outLink()->color), Snake::arrow('D'), $points)->withLabel($this->factory->label($tile->outLink()->display));
    }

    private function bar(?string $stereogroup, TextBlock $label = new EmptyBlock()): FtileBar
    {
        $color = PlantUmlColor::parse((string) Stereogroup::back($stereogroup)) ?? $this->factory->styles->style([], 'activityBar')->color('BackGroundColor', '#555555');

        return new FtileBar(thin: false, color: $color, label: $label);
    }

    /** How far a label on its arrows reaches past its right. */
    private function labelReach(Ftile $tile): float
    {
        $geometry = $tile->geometry();
        $reach = 0.0;
        foreach ([$tile->inLink()->display, $tile->outLink()->display] as $display) {
            $width = $this->factory->label($display)?->size()[0] ?? 0.0;
            $reach = \max($reach, $geometry->left + $width - $geometry->width);
        }

        return $reach;
    }
}
