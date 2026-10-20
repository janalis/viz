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

    public function testALoopWidensItsNodesRightSide(): void
    {
        // Between b and c, the node separation and the loop's reach.
        $placement = self::place(['a', 'b', 'c'], [['a', 'b'], ['a', 'c'], ['b', 'b']]);

        $this->assertSame(36.0, $placement->endpoints['c']->x - $placement->endpoints['b']->x - 54.0);
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

    public function testLaidOutAcrossAClusterListsItsNodesTheOtherWay(): void
    {
        $edges = [['a', 'b'], ['a', 'c']];
        $down = self::place(['b', 'c', 'a'], $edges, [new LayoutCluster('x', '')], ['b' => 'x', 'c' => 'x']);
        $across = self::place(['b', 'c', 'a'], $edges, [new LayoutCluster('x', '')], ['b' => 'x', 'c' => 'x'], Direction::LeftRight);
        $plain = self::place(['b', 'c', 'a'], $edges, direction: Direction::LeftRight);

        // dot: b left of c laid out down, but c above b across, though b stays above c out of a cluster.
        $this->assertLessThan($down->endpoints['c']->x, $down->endpoints['b']->x);
        $this->assertLessThan($across->endpoints['b']->y, $across->endpoints['c']->y);
        $this->assertLessThan($plain->endpoints['c']->y, $plain->endpoints['b']->y);
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

    public function testAFlatEdgeKeepsItsTailFirstUnlessItConstrainsNothing(): void
    {
        $place = static fn (Direction $direction, LayoutEdge ...$edges): Placement => Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('b', 'b', 54, 36), new LayoutNode('a', 'a', 54, 36)],
            $edges,
            direction: $direction,
            rankSets: [new LayoutRankSet(RankKind::Same, ['a', 'b'])],
        )));
        $edge = new LayoutEdge(0, 'a', 'b');
        $back = new LayoutEdge(1, 'b', 'a');
        $down = $place(Direction::TopBottom, $edge);
        $across = $place(Direction::LeftRight, $edge);
        $cycle = $place(Direction::TopBottom, $edge, $back);
        $turnedCycle = $place(Direction::LeftRight, $edge, $back);
        $loose = $place(Direction::TopBottom, new LayoutEdge(0, 'a', 'b', constraint: false));

        $this->assertLessThan($down->endpoints['b']->x, $down->endpoints['a']->x, 'as dot: a before b');
        $this->assertLessThan($across->endpoints['b']->y, $across->endpoints['a']->y, 'across, as dot: a above b');
        $this->assertLessThan($cycle->endpoints['a']->x, $cycle->endpoints['b']->x, 'as dot: the cycle broken from b, met first');
        $this->assertLessThan($turnedCycle->endpoints['b']->y, $turnedCycle->endpoints['a']->y, 'across, as dot: broken from a');
        $this->assertLessThan($loose->endpoints['a']->x, $loose->endpoints['b']->x, 'unconstrained, the nodes keep their order');
    }

    public function testALongEdgePullsItsRealEndsNoHarderThanAPlainOne(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36), ['a', 'x', 'y', 'b', 'w', 'c']),
            [new LayoutEdge(0, 'x', 'b'), new LayoutEdge(1, 'y', 'w'), new LayoutEdge(2, 'b', 'c'), new LayoutEdge(3, 'w', 'c'), new LayoutEdge(4, 'a', 'c')],
            spacing: new Spacing(36, 18, 4),
            rankSets: [new LayoutRankSet(RankKind::Same, ['a', 'x', 'y'])],
        )));

        $this->assertSame([[31.0, 22.0], [103.0, 22.0], [175.0, 22.0], [103.0, 94.0], [175.0, 94.0], [103.0, 166.0]], self::centres($placement), 'dot: c under b, not pulled to a');
        $this->assertSame([206.0, 188.0], [$placement->graph->width, $placement->graph->height], 'dot: 198 by 180, and the pad');
    }

    public function testOrderingKeepsANodesEdgesInTheirOrder(): void
    {
        $order = static function (?string $ordering, LayoutEdge ...$edges): array {
            $placement = Work::run(new DotEngine()->place(new LayoutGraph(
                \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36, ordering: 'a' === $id ? $ordering : null), ['a', 'b', 'c', 'd']),
                $edges,
            )));
            $names = ['b', 'c'];
            \usort($names, static fn (string $x, string $y): int => $placement->endpoints[$x]->x <=> $placement->endpoints[$y]->x);

            return $names;
        };
        $down = [new LayoutEdge(0, 'a', 'b'), new LayoutEdge(1, 'a', 'c'), new LayoutEdge(2, 'd', 'b')];
        $up = [new LayoutEdge(0, 'b', 'a'), new LayoutEdge(1, 'c', 'a'), new LayoutEdge(2, 'b', 'd')];

        $this->assertSame(['c', 'b'], $order(null, ...$down), 'unordered, b goes by d');
        $this->assertSame(['b', 'c'], $order('out', ...$down), 'as dot: b, then c, as a\'s edges out come');
        $this->assertSame(['b', 'c'], $order('in', ...$up), 'and as its edges in come');
        $this->assertSame(['c', 'b'], $order('in', ...$down), 'in orders nothing below');
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

    public function testAGraphNeedingTooManyVirtualNodesIsRefused(): void
    {
        $edges = [];
        for ($index = 0; $index < 250; ++$index) {
            $edges[] = new LayoutEdge($index, 'a', 'b', minLength: 100);
        }

        $this->expectException(\Janalis\Viz\Source\Unsupported::class);

        Work::run(new DotEngine()->place(
            new LayoutGraph([new LayoutNode('a', 'a', 10, 10), new LayoutNode('b', 'b', 10, 10)], [...$edges, new LayoutEdge(250, 'b', 'a', minLength: 100, labelWidth: 1)]),
        ));
    }

    /**
     * @param list<string> $nodes
     * @param list<array{string, string}> $edges
     * @param list<LayoutCluster> $clusters
     * @param array<string, string> $owners
     */
    public function testPortsOrderTheNodesTheyLeadToAndPullTheirEndsLevel(): void
    {
        $graph = static fn (Direction $direction): LayoutGraph => new LayoutGraph(
            \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 108, 36), ['a', 'x', 'y', 'z']),
            [new LayoutEdge(0, 'a', 'x', tailPort: 0.33), new LayoutEdge(1, 'a', 'y'), new LayoutEdge(2, 'a', 'z', tailPort: -0.33)],
            [],
            $direction,
            new Spacing(36, 18, 4),
        );
        $down = Work::run(new DotEngine()->place($graph(Direction::TopBottom)));
        $across = Work::run(new DotEngine()->place($graph(Direction::LeftRight)));

        $this->assertLessThan($down->endpoints['y']->x, $down->endpoints['z']->x, 'z under the left port');
        $this->assertLessThan($down->endpoints['x']->x, $down->endpoints['y']->x);
        $this->assertLessThan($across->endpoints['z']->y, $across->endpoints['x']->y, 'laid out sideways, the order turned over');

        $pulled = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 108, 36), new LayoutNode('b', 'b', 54, 36)],
            [new LayoutEdge(0, 'a', 'b', tailPort: -0.25)],
            [],
            Direction::TopBottom,
            new Spacing(36, 18, 4),
        )));
        $this->assertEqualsWithDelta($pulled->endpoints['a']->x - 27, $pulled->endpoints['b']->x, 1.0, 'b under the port, a quarter of a across from its centre');
    }

    public function testAnAspectStretchesPositionsNeverNodes(): void
    {
        $graph = static fn (Direction $direction, ?float $aspect): LayoutGraph => new LayoutGraph(
            \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36), ['a', 'b', 'c', 'd', 'e']),
            [new LayoutEdge(0, 'a', 'b'), new LayoutEdge(1, 'a', 'c'), new LayoutEdge(2, 'a', 'd'), new LayoutEdge(3, 'b', 'e')],
            [new LayoutCluster('x', '')],
            $direction,
            new Spacing(36, 18, 4),
            aspect: $aspect,
        );
        $size = static fn (Placement $placement): array => [$placement->graph->width - 8, $placement->graph->height - 8];

        // dot: 198 by 180, 198 by 396 at ratio 2, 900 by 180 at 0.2, 234 by 468 laid out across at 2.
        $this->assertSame([198.0, 180.0], $size(Work::run(new DotEngine()->place($graph(Direction::TopBottom, aspect: null)))));
        $tall = Work::run(new DotEngine()->place($graph(Direction::TopBottom, aspect: 2.0)));
        $this->assertEqualsWithDelta([198.0, 396.0], $size($tall), 1e-9);
        $this->assertEqualsWithDelta(356.4, $tall->endpoints['e']->y - 4, 1e-9);
        $this->assertSame(54.0, $tall->endpoints['e']->w);
        $this->assertEqualsWithDelta([900.0, 180.0], $size(Work::run(new DotEngine()->place($graph(Direction::TopBottom, aspect: 0.2)))), 1e-9);
        $this->assertEqualsWithDelta([234.0, 468.0], $size(Work::run(new DotEngine()->place($graph(Direction::LeftRight, aspect: 2.0)))), 1e-9);
        $this->assertSame([198.0, 180.0], $size(Work::run(new DotEngine()->place($graph(Direction::TopBottom, aspect: 0.0)))), 'no aspect at nought');
    }

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
