<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Ftile\FtileBreak;
use Janalis\Viz\PlantUML\Ftile\FtileDecorate;
use Janalis\Viz\PlantUML\Ftile\FtileEmpty;
use Janalis\Viz\PlantUML\Ftile\FtileForkInner;
use Janalis\Viz\PlantUML\Ftile\FtileGroup;
use Janalis\Viz\PlantUML\Ftile\FtileKilled;
use Janalis\Viz\PlantUML\Ftile\FtileLane;
use Janalis\Viz\PlantUML\Ftile\FtileWithNote;
use Janalis\Viz\PlantUML\Ftile\Genealogy;
use Janalis\Viz\PlantUML\Ftile\Label;
use Janalis\Viz\PlantUML\Ftile\MergeStrategy;
use Janalis\Viz\PlantUML\Ftile\Opale;
use Janalis\Viz\PlantUML\Ftile\Rainbow;
use Janalis\Viz\PlantUML\Ftile\Snake;
use Janalis\Viz\PlantUML\Ftile\Worm;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FtileTest extends TestCase
{
    /** @return iterable<string, array{list<array{float, float}>, list<array{float, float}>, MergeStrategy}> */
    public static function worms(): iterable
    {
        yield 'down, left, down, right, down: straight down' => [[[0.0, 0.0], [0.0, 10.0], [-5.0, 10.0], [-5.0, 20.0], [0.0, 20.0], [0.0, 30.0]], [[0.0, 0.0], [0.0, 30.0]], MergeStrategy::Full];
        yield 'right, down, right, up, right: straight across' => [[[0.0, 0.0], [10.0, 0.0], [10.0, 5.0], [20.0, 5.0], [20.0, 0.0], [30.0, 0.0]], [[0.0, 0.0], [30.0, 0.0]], MergeStrategy::Full];
        yield 'down, right, down, right, up, right: down then across' => [
            [[0.0, -10.0], [0.0, 0.0], [10.0, 0.0], [10.0, 5.0], [20.0, 5.0], [20.0, 0.0], [30.0, 0.0]],
            [[0.0, -10.0], [0.0, 0.0], [30.0, 0.0]],
            MergeStrategy::Full,
        ];
        yield 'down, right, down, right: one turn' => [[[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [10.0, 20.0], [20.0, 20.0]], [[0.0, 0.0], [0.0, 20.0], [20.0, 20.0]], MergeStrategy::Full];
        yield 'down, left, down, right past the start: one turn' => [[[0.0, 0.0], [0.0, 10.0], [-5.0, 10.0], [-5.0, 20.0], [10.0, 20.0]], [[0.0, 0.0], [0.0, 20.0], [10.0, 20.0]], MergeStrategy::Full];
        yield 'down, right, down, left well past the start: one turn' => [
            [[0.0, 0.0], [0.0, 10.0], [5.0, 10.0], [5.0, 20.0], [-10.0, 20.0]],
            [[0.0, 0.0], [0.0, 20.0], [-10.0, 20.0]],
            MergeStrategy::Full,
        ];
        yield 'back on itself' => [[[0.0, 0.0], [10.0, 0.0], [5.0, 0.0]], [[0.0, 0.0], [5.0, 0.0]], MergeStrategy::Full];
        yield 'right, down, left, down: across then down' => [[[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [10.0, 10.0], [10.0, 20.0]], [[0.0, 0.0], [10.0, 0.0], [10.0, 20.0]], MergeStrategy::Full];
        yield 'left, down, left, down: across then down' => [[[0.0, 0.0], [-10.0, 0.0], [-10.0, 10.0], [-20.0, 10.0], [-20.0, 20.0]], [[0.0, 0.0], [-20.0, 0.0], [-20.0, 20.0]], MergeStrategy::Full];
        yield 'left, down, left, down, merged less: kept' => [
            [[0.0, 0.0], [-10.0, 0.0], [-10.0, 10.0], [-20.0, 10.0], [-20.0, 20.0]],
            [[0.0, 0.0], [-10.0, 0.0], [-10.0, 10.0], [-20.0, 10.0], [-20.0, 20.0]],
            MergeStrategy::Limited,
        ];
    }

    /**
     * @param list<array{float, float}> $points
     * @param list<array{float, float}> $expected
     */
    #[DataProvider('worms')]
    public function testWormsMergeTheirTurnsAway(array $points, array $expected, MergeStrategy $strategy): void
    {
        $worm = new Worm();
        foreach ($points as [$x, $y]) {
            $worm->add($x, $y);
        }
        $end = new Worm();
        $end->add(...$worm->last());

        $this->assertSame($expected, $worm->merge($end, $strategy)->points);
    }

    public function testAnArrowWithoutTheStepItEmphasisesHasNoArrowheadMidway(): void
    {
        $snake = Snake::through(new Rainbow(), null, [[0.0, 0.0], [10.0, 0.0]])->emphasizing('U');

        $this->assertCount(1, $snake->shapes(new Pen('#000000')));
    }

    public function testATileInASwimlaneIsTheTileItHolds(): void
    {
        $out = new LinkRendering('#red');
        $lane = new FtileLane(new FtileDecorate(new FtileEmpty(4.0, 2.0), out: $out), 'A');

        $this->assertSame($out, $lane->outLink());
        $this->assertSame([[0.0, 0.0], 4.0, 'A', 'A'], [$lane->translateFor($lane->tile), $lane->geometry()->width, FtileLane::in($lane), FtileLane::out($lane)]);
        $this->assertNull(FtileLane::in(new FtileEmpty()));
    }

    public function testTilesAroundABreakFindWhereItStands(): void
    {
        $break = new FtileBreak();
        $style = new Style([]);
        $note = new Opale(new Label(['n'], $style), $style, '#ffffff');
        $tiles = [
            new FtileGroup($break, new Label(['g'], $style), $style, 'none', 'group', new Pen('#000000')),
            FtileWithNote::several($break, [$note], []),
            FtileWithNote::one($break, $note, 'left'),
            new FtileKilled($break),
            new FtileForkInner([new FtileEmpty(30.0, 10.0), $break]),
        ];
        $found = \array_map(static fn ($tile): array => new Genealogy($tile)->translate($break), $tiles);

        $this->assertGreaterThan(0.0, $found[0][0]);
        $this->assertGreaterThan(0.0, $found[1][0]);
        $this->assertSame([0.0, 0.0], $found[2]);
        $this->assertSame([0.0, 0.0], $found[3]);
        $this->assertSame([30.0, 0.0], $found[4]);
        $this->assertSame([0.0, 0.0], new FtileEmpty()->translateFor($break));
        $this->assertTrue(new FtileEmpty()->outLink()->none());
    }
}
