<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\LayoutRankSet;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

/** Positions checked against dot's own, its 4-point pad added. */
final class DotEngineTest extends TestCase
{
    public function testADiamondLaysOutAsDotDoes(): void
    {
        $placement = self::place(['a', 'b', 'c', 'd'], [['a', 'b'], ['a', 'c'], ['b', 'd'], ['c', 'd']]);

        $this->assertSame([[67.0, 22.0], [31.0, 94.0], [103.0, 94.0], [67.0, 166.0]], self::centres($placement));
        $this->assertSame([134.0, 188.0], [$placement->graph->width, $placement->graph->height], 'dot: 126 by 180, and the pad');
        $this->assertSame([[67.0, 22.0], [31.0, 94.0]], $placement->routes[0]);
        $this->assertSame(['' => Direction::TopBottom], $placement->directions);
    }

    public function testAClusterKeepsItsMarginAndTheGraphKeepsOneAroundIt(): void
    {
        $placement = self::place(['e', 'b'], [], [new LayoutCluster('x', 'X', labelHeight: 16.5)], ['e' => 'x']);
        $cluster = $placement->graph->clusters[0];

        // dot: cluster 8,8 to 78,84.5 in a graph 142 wide, e at 43; the node b 18 past e.
        $this->assertSame([12.0, 12.0, 82.0, 88.5], [$cluster->x0, $cluster->y0, $cluster->x1, $cluster->y1]);
        $this->assertSame([47.0, 62.5], [$placement->endpoints['e']->x, $placement->endpoints['e']->y]);
        $this->assertSame(119.0, $placement->endpoints['b']->x);
    }

    public function testNestedAndEmptyClustersAndClustersSideBySide(): void
    {
        $placement = self::place(
            ['e', 'f', 'g'],
            [['e', 'g']],
            [new LayoutCluster('outer', ''), new LayoutCluster('inner', '', 'outer'), new LayoutCluster('other', ''), new LayoutCluster('empty', '')],
            ['e' => 'inner', 'f' => 'other'],
        );
        [$outer, $inner, $other] = $placement->graph->clusters;

        $this->assertSame(8.0, $inner->x0 - $outer->x0, 'a cluster holds its sub-cluster a margin in');
        $this->assertSame(8.0, $inner->y0 - $outer->y0);
        $this->assertGreaterThanOrEqual(8.0, \min(\abs($other->x0 - $outer->x1), \abs($outer->x0 - $other->x1)), 'sibling clusters keep a margin apart');
    }

    public function testEveryDirectionTurnsTheSameLayout(): void
    {
        $nodes = ['a', 'b'];
        $edges = [['a', 'b']];
        $top = self::place($nodes, $edges);
        $bottom = self::place($nodes, $edges, direction: Direction::BottomTop);
        $left = self::place($nodes, $edges, direction: Direction::LeftRight);
        $right = self::place($nodes, $edges, direction: Direction::RightLeft);

        $this->assertSame([[31.0, 22.0], [31.0, 94.0]], self::centres($top));
        $this->assertSame([[31.0, 94.0], [31.0, 22.0]], self::centres($bottom));
        $this->assertSame([[31.0, 22.0], [121.0, 22.0]], self::centres($left), 'dot: 27,18 and 117,18');
        $this->assertSame([[121.0, 22.0], [31.0, 22.0]], self::centres($right));
        $this->assertSame([152.0, 44.0], [$left->graph->width, $left->graph->height]);
        $this->assertSame(['' => Direction::RightLeft], $right->directions);
    }

    public function testLabelsLoopsFlatEdgesParallelEdgesAndRankSets(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 54, 36), new LayoutNode('b', 'b', 54, 36), new LayoutNode('c', 'c', 54, 36)],
            [
                new LayoutEdge(0, 'a', 'b', labelWidth: 20, labelHeight: 10),
                new LayoutEdge(1, 'a', 'b'),
                new LayoutEdge(2, 'a', 'b'),
                new LayoutEdge(3, 'b', 'b'),
                new LayoutEdge(4, 'b', 'c', constraint: false),
                new LayoutEdge(5, 'a', 'ghost'),
            ],
            direction: Direction::LeftRight,
            rankSets: [new LayoutRankSet(RankKind::Same, ['b', 'c', 'ghost'])],
        )));

        $this->assertArrayHasKey(0, $placement->labels, 'a labelled edge has a label node');
        $this->assertCount(3, $placement->routes[0], 'its chain runs through the label rank');
        $this->assertSame($placement->routes[1], $placement->routes[2], 'parallel edges share one chain');
        $this->assertCount(2, $placement->routes[4], 'a flat edge runs straight between its ends');
        $this->assertArrayNotHasKey(3, $placement->routes, 'a loop has no route of its own');
        $this->assertArrayNotHasKey(5, $placement->routes, 'an edge to no node is dropped');
        $this->assertSame($placement->endpoints['b']->x, $placement->endpoints['c']->x, 'b and c share a rank');
    }

    public function testEveryStepYields(): void
    {
        $steps = 0;
        foreach (new DotEngine()->place(new LayoutGraph([new LayoutNode('a', 'a', 10, 10)], [])) as $_) {
            ++$steps;
        }

        $this->assertGreaterThan(0, $steps);
        $this->assertSame([1, 0, 0, 0], [\count(Work::run(new DotEngine()->place(new LayoutGraph([new LayoutNode('a', 'a', 10, 10)], [])))->graph->nodes), 0, 0, 0]);
        $empty = Work::run(new DotEngine()->place(new LayoutGraph([], [])));
        $this->assertSame([8.0, 8.0], [$empty->graph->width, $empty->graph->height], 'an empty graph is its pad');
    }

    /**
     * @param list<string> $nodes
     * @param list<array{string, string}> $edges
     * @param list<LayoutCluster> $clusters
     * @param array<string, string> $owners
     */
    private static function place(array $nodes, array $edges, array $clusters = [], array $owners = [], Direction $direction = Direction::TopBottom): Placement
    {
        return Work::run(new DotEngine()->place(new LayoutGraph(
            \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36, $owners[$id] ?? null), $nodes),
            \array_map(static fn (array $edge, int $index): LayoutEdge => new LayoutEdge($index, $edge[0], $edge[1]), $edges, \array_keys($edges)),
            $clusters,
            $direction,
            new Spacing(36, 18, 4),
        )));
    }

    /** @return list<array{float, float}> */
    private static function centres(Placement $placement): array
    {
        return \array_values(\array_map(static fn ($node): array => [(float) $node->x, (float) $node->y], $placement->graph->nodes));
    }
}
