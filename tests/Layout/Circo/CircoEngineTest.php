<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Circo;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Circo\CircoBlocks;
use Janalis\Viz\Layout\Circo\CircoEngine;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class CircoEngineTest extends TestCase
{
    public function testBlocksAreWhatNoSingleNodesRemovalParts(): void
    {
        // a, b, c a triangle; c - d a bridge; d, e, f a triangle; a - x; y alone.
        $neighbours = [[1, 2, 6], [0, 2], [1, 0, 3], [2, 4, 5], [3, 5], [4, 3], [0], []];

        $this->assertEqualsCanonicalizing([[0, 1, 2], [2, 3], [3, 4, 5], [0, 6]], CircoBlocks::of($neighbours, 0), 'each in the order the search met it');
        $this->assertSame([[7]], CircoBlocks::of($neighbours, 7), 'a lone node, a block of its own');
    }

    public function testACycleStandsOnACircleAsCircoSetsIt(): void
    {
        $placement = self::place('graph { a -- b -- c -- d -- e -- a }', new CircoEngine());
        [$cx, $cy] = self::centre($placement, ['a', 'b', 'c', 'd', 'e']);

        // dot: radius 5 × (54 + 72) / 2π; e to the right, then d, c, b, a a fifth of a turn on each, up and round.
        foreach (['e' => 0.0, 'd' => 72.0, 'c' => 144.0, 'b' => 216.0, 'a' => 288.0] as $name => $angle) {
            $node = $placement->endpoints[$name];
            $this->assertEqualsWithDelta((5 * 126) / (2 * \M_PI), \hypot($node->x - $cx, $node->y - $cy), 1e-6, $name);
            $this->assertEqualsWithDelta(\fmod(\rad2deg(\atan2($cy - $node->y, $node->x - $cx)) + 360, num2: 360), $angle, 1e-6, $name);
        }
    }

    public function testBlocksHangOutwardFromWhereTheyJoin(): void
    {
        $placement = self::place('graph { a -- b -- c -- a; c -- d; d -- e -- f -- d; c -- g -- h -- c; z }', new CircoEngine(apart: 36.0));
        [$cx, $cy] = self::centre($placement, ['a', 'b', 'c']);
        $c = $placement->endpoints['c'];
        $d = $placement->endpoints['d'];

        $this->assertEqualsWithDelta(54 + 36, \hypot($d->x - $c->x, $d->y - $c->y), 1e-6, 'a bridge a step long');
        $this->assertGreaterThan(\hypot($c->x - $cx, $c->y - $cy), \hypot($d->x - $cx, $d->y - $cy), 'out beyond c');
        $this->assertGreaterThan(\max($placement->endpoints['f']->x, $placement->endpoints['h']->x), $placement->endpoints['z']->x, 'a lone node beside');
    }

    public function testTheDotPipelineDrawsWithCirco(): void
    {
        $wide = Work::run(new DotPipeline()->drawing('graph { layout=circo; mindist=2; a -- b -- c -- a; a -- a }'));
        $narrow = Work::run(new DotPipeline()->drawing('graph { layout=circo; mindist="x"; a -- b -- c -- a }'));

        $this->assertGreaterThan($narrow->width, $wide->width);
    }

    /**
     * @param list<string> $names
     * @return array{float, float}
     */
    private static function centre(Placement $placement, array $names): array
    {
        $xs = \array_map(static fn (string $name): float => $placement->endpoints[$name]->x, $names);
        $ys = \array_map(static fn (string $name): float => $placement->endpoints[$name]->y, $names);

        return [\array_sum($xs) / \count($xs), \array_sum($ys) / \count($ys)];
    }

    private static function place(string $source, CircoEngine $engine): Placement
    {
        return Work::run($engine->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)))));
    }
}
