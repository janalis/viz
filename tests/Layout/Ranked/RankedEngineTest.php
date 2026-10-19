<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Ranked;

use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Layout\Ranked\RankedEngine;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class RankedEngineTest extends TestCase
{
    public function testEdgesRankTheirTargetsAfterTheirSources(): void
    {
        $placement = self::place(new LayoutGraph(
            [new LayoutNode('a', 'A', 10, 4), new LayoutNode('b', 'B', 10, 4, order: 1)],
            [new LayoutEdge(0, 'a', 'b')],
            spacing: new Spacing(rankGap: 8, nodeGap: 6, padding: 4),
        ));

        $a = $placement->endpoints['a'];
        $b = $placement->endpoints['b'];
        $this->assertSame($a->x, $b->x);
        $this->assertSame(12.0, $b->y - $a->y, 'one box height plus the rank gap');
        $this->assertSame(['' => Direction::TopBottom], $placement->directions);
    }

    public function testAClusterFramesItsMembersBelowItsLabel(): void
    {
        $placement = self::place(new LayoutGraph(
            [new LayoutNode('a', 'A', 10, 4, cluster: 'c'), new LayoutNode('b', 'B', 10, 4, cluster: 'c', order: 1)],
            [new LayoutEdge(0, 'a', 'b')],
            [new LayoutCluster('c', 'Cluster', minWidth: 40, labelHeight: 3)],
            Direction::LeftRight,
            titleGap: 1,
        ));

        $frame = $placement->graph->clusters[0];
        $this->assertSame('c', $frame->name);
        $this->assertGreaterThanOrEqual(40.0, $frame->x1 - $frame->x0, 'wide enough for the label');
        $this->assertGreaterThan($frame->y0 + 4.0, $placement->endpoints['a']->y - ($placement->endpoints['a']->h / 2), 'members sit below the label band and title gap');
        $this->assertSame(Direction::LeftRight, $placement->directions['c'], 'an undeclared cluster inherits');
        $this->assertCount(2, $placement->graph->nodes);
    }

    public function testAClusterDeclaresItsDirectionUnlessAnEdgeLeavesIt(): void
    {
        $nodes = [new LayoutNode('a', 'A', 4, 4, cluster: 'c'), new LayoutNode('b', 'B', 4, 4, cluster: 'c', order: 1), new LayoutNode('d', 'D', 4, 4, order: 2)];
        $cluster = [new LayoutCluster('c', '', direction: Direction::LeftRight)];
        $inside = [new LayoutEdge(0, 'a', 'b')];
        $leaving = [...$inside, new LayoutEdge(1, 'b', 'd')];

        $this->assertSame(Direction::LeftRight, self::place(new LayoutGraph($nodes, $inside, $cluster))->directions['c']);
        $this->assertSame(Direction::TopBottom, self::place(new LayoutGraph($nodes, $leaving, $cluster))->directions['c']);
        $this->assertSame(Direction::LeftRight, self::place(new LayoutGraph($nodes, $leaving, $cluster, inheritAcrossLinks: false))->directions['c']);
        $this->assertSame(
            Direction::BottomTop,
            self::place(new LayoutGraph($nodes, $inside, [new LayoutCluster('c', '')], undeclared: Direction::BottomTop))->directions['c'],
            'a cluster declaring nothing takes the configured default',
        );
    }

    public function testAnEdgeAcrossRanksGetsWaypointsAndMarginsWidenABox(): void
    {
        $placement = self::place(new LayoutGraph(
            [new LayoutNode('a', 'A', 4, 4), new LayoutNode('b', 'B', 4, 4, order: 1, marginX: 5, marginY: 5), new LayoutNode('c', 'C', 4, 4, order: 2)],
            [new LayoutEdge(0, 'a', 'b'), new LayoutEdge(1, 'b', 'c'), new LayoutEdge(2, 'a', 'c')],
            direction: Direction::RightLeft,
        ));

        $this->assertCount(2, $placement->routes[2], 'one lane through the middle rank, entered and left');
        $this->assertSame(4.0, $placement->endpoints['b']->w, 'the margin reserves space without growing the box');
        $this->assertGreaterThan($placement->endpoints['b']->x, $placement->endpoints['a']->x, 'right to left');
    }

    private static function place(LayoutGraph $graph): Placement
    {
        return Work::run(new RankedEngine()->place($graph));
    }
}
