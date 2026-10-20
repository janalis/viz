<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Neato;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Neato\NeatoEngine;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class NeatoEngineTest extends TestCase
{
    public function testNodesStandAsFarApartAsThePathsBetweenThem(): void
    {
        $placement = self::place('graph { a -- b -- c; c -- d [len=2]; a -- a; b -- c [label=x] }');

        $this->assertEqualsWithDelta(72.0, self::apart($placement, 'a', 'b'), 1.0, 'an inch along an edge');
        $this->assertEqualsWithDelta(144.0, self::apart($placement, 'a', 'c'), 1.0, 'two along a path of two');
        $this->assertEqualsWithDelta(144.0, self::apart($placement, 'c', 'd'), 1.0, 'and an edge as long as its len');
        $this->assertArrayNotHasKey(3, $placement->routes, 'a loop has no route');
        [$b, $c] = [$placement->endpoints['b'], $placement->endpoints['c']];
        $this->assertSame([($b->x + $c->x) / 2, ($b->y + $c->y) / 2], $placement->labels[4], 'a label midway');
        $this->assertSame([[$b->x, $b->y], [$c->x, $c->y]], $placement->routes[1]);
        foreach ($placement->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(4.0 - 1e-9, $node->x - ($node->w / 2), 'inside the padding');
            $this->assertLessThanOrEqual($placement->graph->width - 4.0 + 1e-9, $node->x + ($node->w / 2));
        }
    }

    public function testTheShortestPathSetsTheDistance(): void
    {
        // Straight from a to b is three inches; round by c, two.
        $placement = self::place('graph { a -- b [len=3]; a -- c; c -- b }');

        $this->assertEqualsWithDelta(144.0, self::apart($placement, 'a', 'b'), 1.0);
    }

    public function testConnectedPartsStandSideBySide(): void
    {
        $placement = self::place('graph { a -- b; c -- d; e }');
        $right = static fn (string $name): float => $placement->endpoints[$name]->x + ($placement->endpoints[$name]->w / 2);
        $left = static fn (string $name): float => $placement->endpoints[$name]->x - ($placement->endpoints[$name]->w / 2);

        $this->assertLessThan(\min($left('c'), $left('d')), \max($right('a'), $right('b')));
        $this->assertLessThan($left('e'), \max($right('c'), $right('d')));
    }

    public function testATooLargePartIsLeftToNeato(): void
    {
        $nodes = \array_map(static fn (int $index): LayoutNode => new LayoutNode('n'.$index, '', 10, 10), \range(0, NeatoEngine::MAX_NODES));
        $edges = \array_map(static fn (int $index): LayoutEdge => new LayoutEdge($index, 'n'.$index, 'n'.($index + 1)), \range(0, NeatoEngine::MAX_NODES - 1));

        $this->expectException(Unsupported::class);
        Work::run(new NeatoEngine()->place(new LayoutGraph($nodes, $edges)));
    }

    public function testTheDotPipelineDrawsWithNeatoAndNoClusters(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing('graph { layout=neato; subgraph cluster_x { a -- b } b -- c }'));

        $this->assertCount(2, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind));
        $this->assertCount(3, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind), 'three nodes, no cluster box');
    }

    private static function apart(Placement $placement, string $a, string $b): float
    {
        return \hypot($placement->endpoints[$a]->x - $placement->endpoints[$b]->x, $placement->endpoints[$a]->y - $placement->endpoints[$b]->y);
    }

    private static function place(string $source): Placement
    {
        return Work::run(new NeatoEngine()->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)), labels: static fn (): array => [10.0, 10.0])));
    }
}
