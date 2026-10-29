<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Language\DotParser;
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

    public function testAParentStandsCentredOverItsChildrenAsDotSetsIt(): void
    {
        // As `dot` sets x -> a, a -> b, a -> c: a, and x above it, midway over b and c.
        $placement = self::place(['x', 'a', 'b', 'c'], [['x', 'a'], ['a', 'b'], ['a', 'c']]);
        $at = $placement->endpoints;

        $this->assertSame(($at['b']->x + $at['c']->x) / 2, $at['a']->x);
        $this->assertSame($at['a']->x, $at['x']->x);
    }

    public function testAClusterTurnedSidewaysSpreadsItsRanksForItsLabelAsDotDoes(): void
    {
        $x = static function (array $chain, array $clusters, array $owners): array {
            $nodes = \array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36, $owners[$id] ?? null), $chain);
            $edges = [];
            foreach (\array_slice($chain, offset: 1) as $index => $id) {
                $edges[] = new LayoutEdge($index, $chain[$index], $id);
            }
            $placement = Work::run(new DotEngine()->place(new LayoutGraph($nodes, $edges, $clusters, Direction::LeftRight, new Spacing(36, 18, 4))));

            return \array_map(static fn (string $id): float => $placement->endpoints[$id]->x, $chain);
        };
        $label = new LayoutCluster('c0', '', 'c1', minWidth: 195.0, labelHeight: 11.0);

        // As `dot` sets a -> b -> c with b, or b -> d, in a cluster labelled 195 wide: the ranks
        // reaching half its lack further, the first half a point less; in a cluster beside a, its margin too.
        $this->assertSame([31.0, 191.0, 352.0], $x(['a', 'b', 'c'], [new LayoutCluster('c0', '', minWidth: 195.0, labelHeight: 11.0)], ['b' => 'c0']));
        $this->assertSame([31.0, 146.0, 236.0, 352.0], $x(['a', 'b', 'd', 'c'], [new LayoutCluster('c0', '', minWidth: 195.0, labelHeight: 11.0)], ['b' => 'c0', 'd' => 'c0']));
        $this->assertSame([47.0, 215.0, 384.0], $x(['a', 'b', 'c'], [new LayoutCluster('c1', ''), $label], ['a' => 'c1', 'b' => 'c0', 'c' => 'c1']));
        $this->assertSame([31.0, 191.0, 352.0], $x(['a', 'b', 'c'], [new LayoutCluster('c1', ''), $label], ['b' => 'c0']));
    }

    public function testAnEdgeTakingNoPartInRankingPullsNothingLevelAsDotSetsIt(): void
    {
        // As `dot` sets a -> b -> c with a -> c not constraining: all three in line, the edge around b.
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 54, 36), new LayoutNode('b', 'b', 54, 36), new LayoutNode('c', 'c', 54, 36)],
            [new LayoutEdge(0, 'a', 'b'), new LayoutEdge(1, 'b', 'c'), new LayoutEdge(2, 'a', 'c', constraint: false)],
        )));

        $this->assertSame([31.0, 31.0, 31.0], [$placement->endpoints['a']->x, $placement->endpoints['b']->x, $placement->endpoints['c']->x]);
    }

    public function testALabelledFlatEdgeKeepsItsLabelBetweenItsEndsAndStandsAboveIt(): void
    {
        // As `dot` is seen to set one its ends' ranks make flat: half a separation either side of its label, a
        // separation wide at least; the label midway between the ends' facing sides, half its height and 3 above the edge.
        foreach ([[60.0, 18.0 + 60.0], [10.0, 36.0]] as [$width, $room]) {
            $placement = Work::run(new DotEngine()->place(new LayoutGraph(
                [new LayoutNode('a', 'a', 54, 36), new LayoutNode('b', 'b', 54, 36)],
                [new LayoutEdge(0, 'a', 'b', labelWidth: $width, labelHeight: 14.0)],
                spacing: new Spacing(36, 18, 4),
                rankSets: [new LayoutRankSet(RankKind::Same, ['a', 'b'])],
            )));
            [$a, $b] = [$placement->endpoints['a'], $placement->endpoints['b']];

            $this->assertSame(54.0 + $room, \abs($b->x - $a->x), 'label '.$width);
            $this->assertSame([($a->x + $b->x) / 2, $a->y - 10.0], $placement->labels[0], 'label '.$width);
        }
    }

    public function testAFlatEdgeOfLengthZeroKeepsItsLabelsWidthAndHalfASeparationEitherSide(): void
    {
        // As `dot` is seen to set one: its label's width and half a separation either side, rounded up to a point, however narrow the label.
        foreach ([[10.0, 54.0, 82.0], [60.0, 54.0, 132.0], [9.0, 41.66, 85.0]] as [$width, $node, $apart]) {
            $placement = Work::run(new DotEngine()->place(new LayoutGraph(
                [new LayoutNode('a', 'a', $node, 36), new LayoutNode('b', 'b', $node, 36)],
                [new LayoutEdge(0, 'a', 'b', minLength: 0, labelWidth: $width, labelHeight: 14.0)],
                spacing: new Spacing(36, $node < 54 ? 35 : 18, 4),
            )));

            $this->assertSame($apart, \abs($placement->endpoints['b']->x - $placement->endpoints['a']->x), 'label '.$width);
        }
    }

    public function testAnEdgeSpreadBesideAnotherKeepsItsCurvesMiddleWhereItIsDrawn(): void
    {
        $placement = self::place(['a', 'b'], [['a', 'b'], ['a', 'b']]);
        [$p0, $p1, $p2, $p3] = $placement->controls[1];

        // As dot's are seen to: its curve's middle the full spread across, where its drawing's is.
        $this->assertEqualsWithDelta($placement->splines[1][6][0], ($p0[0] + (3 * $p1[0]) + (3 * $p2[0]) + $p3[0]) / 8, 1e-6);
        $this->assertCount(4, $placement->controls[0]);
    }

    public function testAFlatEdgeOverNodesIsThreeCurvesSharingTheirEnds(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 54, 36), new LayoutNode('b', 'b', 54, 36), new LayoutNode('c', 'c', 54, 36)],
            [new LayoutEdge(0, 'a', 'b', minLength: 0), new LayoutEdge(1, 'b', 'c', minLength: 0), new LayoutEdge(2, 'a', 'c', minLength: 0)],
        )));

        // A start, then two controls and an end for each of its rise, its run along the top and its fall.
        $this->assertCount(10, $placement->controls[2]);
    }

    public function testALabelOverAFlatEdgeBridgingNodesStandsHalfARankSeparationAboveThem(): void
    {
        $place = static fn (array $above): Placement => Work::run(new DotEngine()->place(new LayoutGraph(
            [
                ...\array_map(static fn (string $id): LayoutNode => new LayoutNode($id, $id, 54, 36), $above),
                new LayoutNode('a', 'a', 54, 36),
                new LayoutNode('b', 'b', 54, 36),
                new LayoutNode('c', 'c', 54, 36),
            ],
            [
                ...\array_map(static fn (string $id): LayoutEdge => new LayoutEdge(0, $id, 'a'), $above),
                new LayoutEdge(1, 'a', 'b', minLength: 0),
                new LayoutEdge(2, 'b', 'c', minLength: 0),
                new LayoutEdge(3, 'a', 'c', minLength: 0, labelWidth: 8.0, labelHeight: 40.0),
            ],
            spacing: new Spacing(36, 18, 0),
        )));
        [$lone, $under] = [$place([]), $place(['s'])];

        // As `dot` is seen to set it: midway between its ends, its bottom 18 above their tops, the rank above 18 clear of its top.
        $this->assertSame([99.0, 20.0, 94.0], [$lone->labels[3][0], $lone->labels[3][1], $lone->graph->height]);
        $this->assertSame([112.0, 74.0], [$under->endpoints['a']->y - $under->endpoints['s']->y, $under->labels[3][1]]);
    }

    public function testChainsKeepTheRoomDotIsSeenToKeepBetweenThem(): void
    {
        // Two edges' chains side by side keep two separations between them; on the ranks a graph
        // with edge labels puts between its nodes', one separation and 5. Ends 1.44 wide, as points.
        foreach ([[2, false, 2 + 36.0], [1, true, 2 + 18.0 + 5.0]] as [$length, $labelled, $apart]) {
            $edges = [new LayoutEdge(0, 'a', 't', $length), new LayoutEdge(1, 'b', 't', $length)];
            if ($labelled) {
                $edges[] = new LayoutEdge(2, 'x', 'y', labelWidth: 10.0, labelHeight: 14.0);
            }
            $placement = Work::run(new DotEngine()->place(new LayoutGraph(
                [new LayoutNode('a', 'a', 1.44, 1.44), new LayoutNode('b', 'b', 1.44, 1.44), new LayoutNode('t', 't', 144, 36), new LayoutNode('x', 'x', 54, 36), new LayoutNode('y', 'y', 54, 36)],
                $edges,
                spacing: new Spacing(36, 18, 4),
            )));

            $this->assertSame($apart, \abs($placement->endpoints['b']->x - $placement->endpoints['a']->x), $labelled ? 'labelled' : 'plain');
        }
    }

    public function testALoopWidensItsNodesRightSide(): void
    {
        // Between b and c, the node separation and the loop's reach.
        $placement = self::place(['a', 'b', 'c'], [['a', 'b'], ['a', 'c'], ['b', 'b']]);

        $this->assertSame(36.0, $placement->endpoints['c']->x - $placement->endpoints['b']->x - 54.0);
    }

    public function testLoopsKeepTheRoomDotKeepsThemWhateverTheNodeSeparation(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 54, 36), new LayoutNode('b', 'b', 54, 36)],
            \array_map(static fn (int $index, float $width): LayoutEdge => new LayoutEdge($index, 'a', 'a', labelWidth: $width, labelHeight: 15), [0, 1, 2], [40.0, 10.0, 40.0]),
            [],
            Direction::TopBottom,
            new Spacing(60, 35, 4),
        )));
        $right = $placement->endpoints['a']->x + 27;

        // As `dot` keeps it: 18 for each loop and its label's width, then the node separation.
        $this->assertEqualsWithDelta((18 * 3) + 90 + 35, $placement->endpoints['b']->x - 27 - $right, 1.0);
        // Each loop reaching a node separation past the one before, or past that one's label where wider; its label just past it.
        $this->assertSame([35.0, 75.0, 110.0], \array_map(static fn (array $points): float => \round(\max(\array_column($points, 0)) - $right, precision: 2), \array_values($placement->routes)));
        $this->assertSame([55.0, 80.0, 130.0], \array_map(static fn (array $at): float => \round($at[0] - $right, precision: 2), \array_values($placement->labels)));
    }

    public function testLoopsStandAboveTheirNodeLaidOutLeftToRightAndBulgeDownFirstLaidOutBottomUp(): void
    {
        $place = static fn (Direction $direction): Placement => Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 42, 48)],
            [new LayoutEdge(0, 'a', 'a', labelWidth: 30, labelHeight: 15)],
            [],
            $direction,
            new Spacing(60, 35, 4),
        )));
        $across = $place(Direction::LeftRight);
        $node = $across->endpoints['a'];

        // As `dot` draws it: out a node separation above the node, first to its left, its label just above.
        $this->assertEqualsWithDelta($node->y - 24 - 35, \min(\array_column($across->splines[0], 1)), 1e-9);
        $this->assertLessThan($node->x, $across->splines[0][1][0]);
        $this->assertEqualsWithDelta([$node->x, $node->y - 24 - 35 - 7.5], $across->labels[0], 1e-9);
        // Bounded above by its label, past the room the loop keeps.
        $this->assertEqualsWithDelta(4 + 15 + 35 + 24, $node->y, 1e-9);
        // Bottom up, it bulges down first.
        $up = $place(Direction::BottomTop);
        $this->assertGreaterThan($up->endpoints['a']->y, $up->splines[0][1][1]);
    }

    public function testParallelEdgesKeepTheirRoomTheirLabelsFollowingTheirLinesAsDotSetsThem(): void
    {
        $edges = \array_map(static fn (int $index): LayoutEdge => new LayoutEdge($index, 'a', 'b', labelWidth: 100, labelHeight: 17), [0, 1, 2]);
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 50, 50), new LayoutNode('b', 'b', 50, 50)],
            $edges,
            [],
            Direction::TopBottom,
            new Spacing(60, 35, 4),
        )));
        $lefts = \array_map(static fn (array $at): float => $at[0] - 50, $placement->labels);

        // As `dot` sets three such labels 100 wide: 40 between the first two, held at their nodes; 8 between the last two, the third following its line back.
        $this->assertEqualsWithDelta(140.0, $lefts[1] - $lefts[0], 4.0);
        $this->assertEqualsWithDelta(108.0, $lefts[2] - $lefts[1], 2.0);

        // What follows them keeps its room from the label nodes: as dot sets c, 220 right of the first label.
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 50, 50), new LayoutNode('b', 'b', 50, 50), new LayoutNode('c', 'c', 50, 50)],
            [new LayoutEdge(0, 'a', 'b', labelWidth: 77, labelHeight: 17), new LayoutEdge(1, 'a', 'b', labelWidth: 77, labelHeight: 17), new LayoutEdge(2, 'c', 'b')],
            [],
            Direction::TopBottom,
            new Spacing(60, 35, 4),
        )));
        $this->assertGreaterThanOrEqual(215.0, $placement->endpoints['c']->x - ($placement->labels[0][0] - 38.5));
    }

    public function testAFlatEdgeKeepsItsEndsTogetherAsDotDoes(): void
    {
        // As dot sets b -> n laid side by side with a -> b, b -> c, a -> c: n just right of b, a's long edge past it.
        $graph = Work::run(new DotParser()->parse('digraph { node [shape=rect,width=0.6,height=0.4,label=""]; b -> n [minlen=0]; a -> b; b -> c; a -> c; }'));
        $at = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)))->endpoints;

        $this->assertEqualsWithDelta([61.0, 55.0, 55.0], [$at['n']->x - $at['b']->x, $at['a']->x - $at['b']->x, $at['c']->x - $at['b']->x], 0.5);
    }

    public function testAFlatEdgesLastHeadComesFirstAsDotPutsIt(): void
    {
        // As dot sets a -> b, a -> c, b -> d side by side: c next to a, then b and d.
        $graph = Work::run(new DotParser()->parse('digraph { node [shape=rect,width=0.6,height=0.4,label=""]; {rank=same; a -> b; a -> c; b -> d;} }'));
        $at = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)))->endpoints;

        $this->assertEqualsWithDelta([61.0, 122.0, 183.0], [$at['c']->x - $at['a']->x, $at['b']->x - $at['a']->x, $at['d']->x - $at['a']->x], 0.5);
    }

    public function testAFlatEdgeOverNodesArcsAboveThemAsDotDrawsIt(): void
    {
        // As dot sets a -> c, a -> b side by side, b ordered first: a -> c goes over b, half a rank separation above, the graph as tall as dot makes it.
        foreach (['0.5' => 51.8, '0.833333' => 64.8, '1.5' => 90.2] as $ranksep => $height) {
            $graph = Work::run(new DotParser()->parse('digraph { ranksep='.$ranksep.'; node [shape=rect,width=0.6,height=0.4,label=""]; {rank=same; a -> c; a -> b;} }'));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
            $this->assertEqualsWithDelta($height + 8, $placement->graph->height, 1.0, $ranksep);
            $top = \min(\array_column($placement->splines[0], 1));
            $this->assertLessThan($placement->endpoints['b']->y - 14.4, $top, 'over b');
            $this->assertSame([], $placement->splines[1] ?? [], 'a -> b, to its neighbour, straight');
        }
    }

    public function testAFlatEdgesRunOverWideNodesKeepsWithinTheRoomAboveItsCorners(): void
    {
        // As dot fits a -> c over b: its run's controls 4/9 of its length from its corners, halved while it would rise further above them than they stand above the rank.
        foreach (['2' => 4 / 9, '4' => 2 / 9] as $width => $share) {
            $graph = Work::run(new DotParser()->parse(
                'digraph { nodesep=0.486111; ranksep=0.833333; node [shape=rect,label=""]; a [width=0.77,height=0.666667]; c [width=0.84,height=0.35]; b [width='
                .$width
                .',height=0.35]; a -> c [minlen=0]; a -> b [minlen=0]; }',
            ));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
            [$first, $control, , $last] = \array_slice($placement->controls[0], offset: 3, length: 4);
            $slope = ($first[1] - $control[1]) / ($control[0] - $first[0]);
            $this->assertEqualsWithDelta($share, (($control[0] - $first[0]) * \hypot(1.0, $slope)) / ($last[0] - $first[0]), 1e-9, (string) $width);
        }
    }

    public function testAFlatEdgesArcIsFittedInTwoPiecesWhereOneClearsTheCornersAsDotFitsIt(): void
    {
        // As dot draws a -> c over b: split at the corner farther from a curve from centre to centre, the other side one piece where it keeps above the corners.
        foreach ([
            ['0.84', '2.3', 7,  60.5],
            ['0.3',  '1.0', 7,  81.5],
            ['0.84', '1.0', 10, 60.5],
        ] as [$middle, $head, $count, $joint]) {
            $graph = Work::run(new DotParser()->parse(
                'digraph { nodesep=0.486111; ranksep=0.833333; node [shape=rect,label=""]; a [width=0.711,height=0.666667]; c [width='
                .$head
                .',height=0.35]; b [width='
                .$middle
                .',height=0.35]; a -> c [minlen=0]; a -> b [minlen=0]; }',
            ));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
            $controls = $placement->controls[0];
            $this->assertCount($count, $controls, $middle.' '.$head);
            $this->assertEqualsWithDelta($joint, $controls[3][0] - $placement->endpoints['a']->x, 1.0, $middle.' '.$head);
        }
    }

    public function testAFlatEdgesArcTurnsAtTheGridPointNearestTheSidesOfTheNodesItPasses(): void
    {
        // As dot sets b's corners, 39.96 either side of its centre: 39.5 out, an odd node separation setting the grid off half a point; 40 with an even one.
        foreach (['0.486111' => 39.5, '0.25' => 40.0] as $nodesep => $corner) {
            $graph = Work::run(new DotParser()->parse(
                'digraph { nodesep='.$nodesep.'; node [shape=rect,label=""]; a [width=0.77,height=0.667]; c [width=0.84,height=0.35]; b [width=1.11,height=0.35]; a -> c [minlen=0]; a -> b [minlen=0]; }',
            ));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
            $b = $placement->endpoints['b']->x;
            $this->assertEqualsWithDelta([-$corner, $corner], [$placement->controls[0][3][0] - $b, $placement->controls[0][6][0] - $b], 1e-6, $nodesep);
        }
    }

    public function testALoopBulgesAQuarterOfTheWayToTheNearerRankBeside(): void
    {
        // As dot draws a's loop: 12 aside alone on its rank, a quarter of its height; 27 with b a rank below, a quarter of 24 + 60 + 24.
        foreach (['' => 12.0, 'b [width=0.77,height=0.667]; a -> b;' => 27.0] as $more => $bulge) {
            $graph = Work::run(new DotParser()->parse('digraph { ranksep=0.833333; node [shape=rect,label=""]; a [width=0.58,height=0.667]; a -> a; '.$more.' }'));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
            $this->assertEqualsWithDelta($bulge, $placement->endpoints['a']->y - $placement->controls[0][2][1], 0.01, $more);
        }
    }

    public function testLabelledFlatEdgesBetweenNeighboursStackTheirLabelsAsDotDoes(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 55.44, 48), new LayoutNode('b', 'b', 41.76, 48)],
            \array_map(static fn (int $index): LayoutEdge => new LayoutEdge($index, 'a', 'b', minLength: 0, labelWidth: 36, labelHeight: 17), [0, 1, 2]),
            [],
            Direction::TopBottom,
            new Spacing(60, 35, 4),
        )));
        $y = $placement->endpoints['a']->y;

        // As dot stacks them: the first 3 above the line, the next 3 below, the third 6 above the first.
        $this->assertSame([-11.5, 11.5, -34.5], \array_map(static fn (array $at): float => \round($at[1] - $y, precision: 2), \array_values($placement->labels)));
        // The first straight; the second round the bottom of its own label, the third round the top of the first.
        $this->assertArrayNotHasKey(0, $placement->controls);
        $this->assertSame([20.0, -20.0], [\round($placement->controls[1][3][1] - $y, precision: 2), \round($placement->controls[2][3][1] - $y, precision: 2)]);
    }

    public function testALabelStandsRightOfItsEdgeWhereTheLineCrossesItsRank(): void
    {
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('a', 'a', 14, 50), new LayoutNode('b', 'b', 14, 50), new LayoutNode('c', 'c', 14, 50)],
            [new LayoutEdge(0, 'a', 'b', labelWidth: 50, labelHeight: 17), new LayoutEdge(1, 'a', 'c', labelWidth: 20, labelHeight: 17)],
            [],
            Direction::TopBottom,
            new Spacing(35, 60, 4),
        )));
        $crossing = static function (array $line, float $y): float {
            for ($index = 1; $index < \count($line); ++$index) {
                [[$ax, $ay], [$bx, $by]] = [$line[$index - 1], $line[$index]];
                if ((($ay - $y) * ($by - $y)) <= 0 && $ay !== $by) {
                    return $ax + ((($bx - $ax) * ($y - $ay)) / ($by - $ay));
                }
            }

            return \NAN;
        };

        // As `dot` is seen to set them: each label's left side on its line, which cuts the corner its chain turns.
        foreach ([0 => 50.0, 1 => 20.0] as $edge => $width) {
            [$x, $y] = $placement->labels[$edge];
            $this->assertEqualsWithDelta($crossing($placement->splines[$edge], $y), $x - ($width / 2), 1e-6);
        }
        $this->assertLessThan($placement->endpoints['c']->x, $placement->labels[1][0] - 10.0, 'left of the chain\'s turn below its head');
    }

    public function testAnEdgesOneChainNodeStandsMidwayBetweenItsEnds(): void
    {
        // PlantUML's web --> app : REST, app --> data : SQL, web ..> data : cache, as svek writes it.
        $placement = Work::run(new DotEngine()->place(new LayoutGraph(
            [new LayoutNode('web', 'web', 68.59, 46.49), new LayoutNode('app', 'app', 67.28, 46.49), new LayoutNode('data', 'data', 51.19, 45.49)],
            [
                new LayoutEdge(0, 'web', 'app', labelWidth: 32, labelHeight: 17),
                new LayoutEdge(1, 'app', 'data', labelWidth: 26, labelHeight: 17),
                new LayoutEdge(2, 'web', 'data', labelWidth: 37, labelHeight: 17),
            ],
            [],
            Direction::TopBottom,
            new Spacing(60, 35, 4),
        )));
        [$web, $app] = [$placement->endpoints['web'], $placement->endpoints['app']];
        $y = $placement->labels[0][1];
        $line = $placement->splines[0];
        $at = 0;
        while ($line[$at + 1][1] < $y) {
            ++$at;
        }
        [[$ax, $ay], [$bx, $by]] = [$line[$at], $line[$at + 1]];

        // As `dot` sets it: Web's 52 right of App, as they stand, REST's line half way between them on its label's rank.
        $this->assertEqualsWithDelta(52.0, $web->x - $app->x, 1e-9);
        $this->assertEqualsWithDelta(($web->x + $app->x) / 2, $ax + ((($bx - $ax) * ($y - $ay)) / ($by - $ay)), 2.0);
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
        // As dot draws it laid out left to right, a loop runs out a node separation above its node and back to its centre.
        $this->assertEqualsWithDelta($placement->endpoints['b']->y - ($placement->endpoints['b']->h / 2) - 6, \min(\array_column($placement->splines[3], 1)), 1e-9, 'a loop has its curve');
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

    public function testAnInitialOrderIsTransposedBeforeItsFirstSweep(): void
    {
        $placement = self::place(['a', 'b', 'c', 'd'], [['a', 'b'], ['b', 'c'], ['c', 'd'], ['a', 'c'], ['b', 'd']]);

        // As dot sets it: a's long edge left of b, which its swap with b uncrosses before any sweep, and c under it.
        $this->assertSame([[58.0, 22.0], [86.0, 94.0], [31.0, 166.0], [58.0, 238.0]], self::centres($placement));
    }

    public function testEachEdgeAfterTheFirstWidensTheChainTheyShareByASeparation(): void
    {
        $placement = self::place(['a', 'b', 'c', 'd'], [['a', 'b'], ['c', 'd'], ['b', 'c'], ['a', 'c'], ['a', 'c']]);

        // As dot sets them: the two edges from a to c share a chain 64 right of b, 27 + 18 + 9 + 1 and a separation's half.
        $this->assertSame([31.0, 95.0], [$placement->endpoints['b']->x, $placement->endpoints['c']->x]);
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
        $down = [new LayoutEdge(0, 'a', 'c'), new LayoutEdge(1, 'a', 'b'), new LayoutEdge(2, 'd', 'b')];
        $up = [new LayoutEdge(0, 'c', 'a'), new LayoutEdge(1, 'b', 'a'), new LayoutEdge(2, 'b', 'd')];

        // As dot sets them.
        $this->assertSame(['b', 'c'], $order(null, ...$down), 'unordered, as the nodes were made');
        $this->assertSame(['c', 'b'], $order('out', ...$down), 'c, then b, as a\'s edges out were written');
        $this->assertSame(['c', 'b'], $order('in', ...$up), 'and as its edges in were');
        $this->assertSame(['b', 'c'], $order('in', ...$down), 'in orders nothing below');
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

    public function testTheDrawingIsBoundedByWhatItDraws(): void
    {
        $graph = Work::run(new DotParser()->parse('digraph { node [shape=box, label=""]; a -> b; a -> c; b -> d; c -> d; a -> d }'));
        $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));

        // As `dot` bounds it: the long edge round c bulges past it, not as far as the room kept for its chain, out to whole points.
        $this->assertSame([147.0, 188.0], [$placement->graph->width, $placement->graph->height]);
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
