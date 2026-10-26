<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Dot\DotLayers;
use Janalis\Viz\Layout\Dot\DotPosition;
use Janalis\Viz\Layout\Dot\DotSplines;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotSplinesTest extends TestCase
{
    public function testALongEdgeCurvesAroundTheNodesItPasses(): void
    {
        // a's edge to e passes the rank of b, c and d, which stand in its straight way.
        $placement = self::place('digraph { a -> b; a -> c; a -> d; b -> e; c -> e; d -> e; a -> e; x -> c }');
        $index = 6;
        $spline = $placement->splines[$index];

        $this->assertSame([$placement->endpoints['a']->x, $placement->endpoints['a']->y], $spline[0], 'from the tail centre');
        $this->assertSame([$placement->endpoints['e']->x, $placement->endpoints['e']->y], $spline[\count($spline) - 1], 'to the head centre');
        foreach (['b', 'c', 'd', 'x'] as $name) {
            $node = $placement->endpoints[$name];
            foreach ($spline as [$x, $y]) {
                $this->assertFalse(\abs($x - $node->x) < ($node->w / 2) && \abs($y - $node->y) < ($node->h / 2), 'clear of '.$name);
            }
        }
        $this->assertCount(\count($placement->routes), $placement->splines);
        $corners = $placement->polylines[$index];
        $this->assertSame([$spline[0], $spline[\count($spline) - 1]], [$corners[0], $corners[\count($corners) - 1]], 'its skeleton, corner to corner');
        $this->assertLessThan(\count($spline), \count($corners));
    }

    public function testAnEdgeUpTheRanksRunsFromItsTail(): void
    {
        $placement = self::place('digraph { rankdir=LR; a -> b -> c; c -> a }');
        $spline = $placement->splines[2];

        $this->assertSame([$placement->endpoints['c']->x, $placement->endpoints['c']->y], $spline[0]);
        $this->assertSame([$placement->endpoints['a']->x, $placement->endpoints['a']->y], $spline[\count($spline) - 1]);
    }

    public function testEdgesSharingAPathSpreadAndWidenItsVirtualNodes(): void
    {
        $placement = self::place('digraph { a -> c -> b; a -> b; a -> b; b -> a }');
        $middles = \array_map(static fn (int $index): float => self::middle($placement->splines[$index])[0], [2, 3, 4]);
        $apart = 18 * DotSplines::SPREAD;

        $this->assertEqualsWithDelta([$middles[0] + $apart, $middles[0] + (2 * $apart)], [$middles[1], $middles[2]], 1e-6, 'in the order written, the way back too');
        $c = $placement->endpoints['c'];
        foreach ([2, 3, 4] as $index) {
            foreach ($placement->splines[$index] as [$x, $y]) {
                $this->assertFalse(\abs($x - $c->x) < ($c->w / 2) && \abs($y - $c->y) < ($c->h / 2), 'the spread keeps clear of c');
            }
        }
        $start = $placement->splines[2][0];
        $this->assertLessThan(27.0, \abs($start[0] - $placement->endpoints['a']->x), 'starting inside the tail, though spread');
    }

    public function testAnEdgeKeepsLeftOfItsLabelAndOutOfClustersItIsNotIn(): void
    {
        $placement = self::place('digraph { a -> b -> c -> d; a -> d [label="a long label"]; subgraph cluster_x { e -> f } a -> e }');
        $label = $placement->labels[3];
        $spline = $placement->splines[3];
        [$x] = self::nearest($spline, $label[1]);

        $this->assertLessThan($label[0], $x, 'the label stands right of its edge');

        $outside = self::place('digraph { s -> t -> u; s -> u; subgraph cluster_x { p -> q -> r } s -> p; v -> q }');
        $cluster = $outside->graph->clusters[0];
        foreach ($outside->splines[2] as [$x, $y]) {
            $this->assertFalse($x > $cluster->x0 && $x < $cluster->x1 && $y > $cluster->y0 && $y < $cluster->y1, 'clear of the cluster');
        }
    }

    public function testALabelOnAnEdgeToTheNextRankHangsByItsTopRightCornerFromTheEdge(): void
    {
        // i -> m runs to the rank of j -> i's label, with no rank between for its own.
        $source = 'digraph { node [shape=box]; k -> l [minlen=0]; i -> m [minlen=0, label="false"]; h -> i; i -> j [label="true"]; j -> k; m -> l [label="Ending process"] }';
        $placement = self::place($source);
        $graph = Work::run(new DotParser()->parse($source));
        [$width, $height] = DotNodes::edgeText($graph, $graph->edges[1])->size();
        [$x, $y] = $placement->labels[1];
        [$i, $m] = [$placement->endpoints['i'], $placement->endpoints['m']];
        $corner = [$x + ($width / 2), $y - ($height / 2)];

        $this->assertGreaterThan($i->y, $corner[1], 'below i');
        $this->assertLessThan($m->y, $corner[1], 'above m, a rank below');
        $this->assertLessThan(0.5, \abs($corner[0] - self::nearest($placement->splines[1], $corner[1])[0]), 'the corner on the edge');
    }

    public function testAnEdgeTurnsRoundTheCornerOfAClusterBetweenRanks(): void
    {
        // s's edge to e comes down right of the cluster, then turns under it to e, below and left.
        $placement = self::place('digraph { node [shape=box]; s -> p -> c -> l -> d -> e; s -> g -> l; g -> d; l -> f -> e; subgraph cluster_n { l; d } s -> e }');
        $cluster = $placement->graph->clusters[0];
        $spline = $placement->splines[10];

        $this->assertLessThan($cluster->x1, $placement->endpoints['e']->x, 'e stands left of the cluster\'s right side');
        foreach ($spline as [$x, $y]) {
            $this->assertFalse($x > ($cluster->x0 + 0.5) && $x < ($cluster->x1 - 0.5) && $y > ($cluster->y0 + 0.5) && $y < ($cluster->y1 - 0.5), 'clear of the cluster');
        }
    }

    public function testACurveThatCannotFitIsSplitAtItsSharpestCorner(): void
    {
        // A wide neighbour forces the path round a corner, then straight on down.
        $placement = self::place('digraph { nodesep=0.05; a -> b -> c -> d -> e; a -> e; a -> w; w -> x -> y; w [width=4]; x [width=4]; y [width=4] }');
        $spline = $placement->splines[4];

        $this->assertGreaterThan(12 + 1, \count($spline), 'more than one curve');
        foreach (['w', 'x', 'y', 'b', 'c', 'd'] as $name) {
            $node = $placement->endpoints[$name];
            foreach ($spline as [$x, $y]) {
                $this->assertFalse(\abs($x - $node->x) < (($node->w / 2) - 0.5) && \abs($y - $node->y) < (($node->h / 2) - 0.5), 'clear of '.$name);
            }
        }
    }

    public function testTheShortestPathTurnsAtTheCornerItCannotCut(): void
    {
        // a over c, two ranks down; their virtual node at a's x, b wide beside it, from 10.
        $layers = new DotLayers([0, 1, 2], [[20.0, 10.0], [40.0, 10.0], [20.0, 10.0]], [-1, -1, -1], [], [[0, 2, 1, 0.0, 0.0]]);
        $layers->ranks[1] = [3, 1];
        $position = new DotPosition($layers, [], 10.0, 40.0);
        $position->x = [0.0, 30.0, 40.0, 0.0];
        $position->y = [0.0, 50.0, 100.0];
        $position->width = 60.0;
        $router = new DotSplines($layers, $position, 10.0);

        // The room beside the virtual node ends 5 short of b: the path turns at its lower corner.
        [$spline, $corners] = $router->route([0, 3, 2]);
        $this->assertEqualsWithDelta([[0.0, 0.0], [5.0, 55.0], [40.0, 100.0]], $corners, 1e-9);
        $this->assertSame([[0.0, 0.0], [40.0, 100.0]], [$spline[0], $spline[\count($spline) - 1]]);
        foreach ($spline as [$x, $y]) {
            $this->assertFalse($x > 5.01 && $y > 44.99 && $y < 55.01, 'clear of b\'s room');
        }
        $this->assertSame(
            [[40.0, 100.0], [5.0, 55.0], [0.0, 0.0]],
            \array_map(static fn (array $point): array => [\round($point[0], precision: 9) + 0.0, \round($point[1], precision: 9) + 0.0], $router->route([2, 3, 0])[1]),
            'up the ranks, the same path back',
        );

        // Spread, as dot is seen to start them: the ends at three fifths of the spread, no further than 0.37 past the narrower half width, 10.37; the middle nearly all 20.
        [, $spread] = $router->route([0, 3, 2], offset: 20.0);
        $along = \hypot(5.0, y: 55.0) / (\hypot(5.0, y: 55.0) + \hypot(35.0, y: 45.0));
        $this->assertEqualsWithDelta([[10.37, 0.0], [5.0 + 10.37 + (9.63 * \sin(\M_PI * $along)), 55.0], [50.37, 100.0]], $spread, 1e-9);
        [, $near] = $router->route([0, 3, 2], offset: 2.0);
        $this->assertEqualsWithDelta([[1.2, 0.0], [5.0 + 1.2 + (0.8 * \sin(\M_PI * $along)), 55.0], [41.2, 100.0]], $near, 1e-9, 'a small spread starts at three fifths of itself');
    }

    /**
     * @param list<array{float, float}> $points
     * @return array{float, float}
     */
    private static function middle(array $points): array
    {
        return $points[\intdiv(\count($points), num2: 2)];
    }

    /**
     * @param list<array{float, float}> $points
     * @return array{float, float}
     */
    private static function nearest(array $points, float $y): array
    {
        \usort($points, static fn (array $a, array $b): int => \abs($a[1] - $y) <=> \abs($b[1] - $y));

        return $points[0];
    }

    private static function place(string $source): Placement
    {
        $graph = Work::run(new DotParser()->parse($source));

        return Work::run(new DotEngine()->place(DotLayoutInput::from($graph, labels: static fn (DotEdge $edge): array => DotNodes::edgeText($graph, $edge)->size())));
    }
}
