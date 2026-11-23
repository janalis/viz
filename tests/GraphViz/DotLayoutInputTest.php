<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotLayoutInputTest extends TestCase
{
    public function testSizesSpacingAndEdgeAttributesComeInPoints(): void
    {
        $input = self::input('digraph { rankdir=lr; nodesep=0.5; ranksep=1; a [width=1, height=2]; b [width=x]; a -> b [minlen=3, weight=2, constraint=false, label=hi] }');

        $this->assertSame([72.0, 144.0], [$input->nodes[0]->width, $input->nodes[0]->height]);
        $this->assertSame([54.0, 36.0], [$input->nodes[1]->width, $input->nodes[1]->height], 'dot\'s defaults where a size does not read');
        $this->assertSame([Direction::LeftRight, 72.0, 36.0], [$input->direction, $input->spacing->rankGap, $input->spacing->nodeGap]);
        $edge = $input->edges[0];
        $this->assertSame([3, 2, false, 0.0], [$edge->minLength, $edge->weight, $edge->constraint, $edge->labelWidth], 'a label has no size without a measure');
    }

    public function testMeasuresGrowNodesAndGiveLabelsTheirSize(): void
    {
        $input = DotLayoutInput::from(
            Work::run(new DotParser()->parse('digraph { a [label="long"]; b [fixedsize=true]; a -> b [label=x]; subgraph cluster_t { label=T; c } subgraph cluster_u { d } }')),
            static fn (\Janalis\Viz\GraphViz\Model\DotNode $node): array => [100.0, 10.0],
            static fn (\Janalis\Viz\GraphViz\Model\DotEdge $edge): array => [20.0, 12.0],
            static fn (\Janalis\Viz\GraphViz\Model\DotSubgraph $cluster): array => [30.0, 14.0],
        );

        $this->assertSame([100.0, 36.0], [$input->nodes[0]->width, $input->nodes[0]->height]);
        $this->assertSame([54.0, 36.0], [$input->nodes[1]->width, $input->nodes[1]->height], 'a fixed size keeps its own');
        $this->assertSame([20.0, 12.0], [$input->edges[0]->labelWidth, $input->edges[0]->labelHeight]);
        $this->assertSame([30.0, 14.0], [$input->clusters[0]->minWidth, $input->clusters[0]->labelHeight]);
        $this->assertSame([0.0, 0.0], [$input->clusters[1]->minWidth, $input->clusters[1]->labelHeight], 'a cluster without a label has none to measure');
    }

    public function testAPointIsASmallRoundDotWithoutALabel(): void
    {
        $input = DotLayoutInput::from(
            Work::run(new DotParser()->parse('digraph { node [shape=point]; a; b [width=1, height=0.5]; }')),
            static fn (\Janalis\Viz\GraphViz\Model\DotNode $node): array => [100.0, 100.0],
        );

        $this->assertEqualsWithDelta([3.6, 3.6], [$input->nodes[0]->width, $input->nodes[0]->height], 1e-9);
        $this->assertSame([36.0, 36.0], [$input->nodes[1]->width, $input->nodes[1]->height]);
    }

    public function testAPointIsAsWideAsTheSizeWritten(): void
    {
        $input = self::input('digraph { node [shape=point]; w [width=0.3]; h [height=0.3]; both [width=0.3, height=0.2]; }');

        // As `dot` sizes them: the size written, the smaller of both.
        $this->assertEqualsWithDelta([21.6, 21.6, 14.4], [$input->nodes[0]->width, $input->nodes[1]->height, $input->nodes[2]->width], 1e-9);
    }

    public function testARoundOrRegularNodeIsAsWideAsTall(): void
    {
        $input = self::input('digraph { a [shape=circle, width=1, height=0.5]; b [shape=circle, width=0.3, height=0.5, fixedsize=true]; c [shape=box, regular=true, width=1, height=0.5]; }');

        // As `dot` sizes them: the larger of their sizes, fixed or not.
        $this->assertSame([[72.0, 72.0], [36.0, 36.0], [72.0, 72.0]], \array_map(static fn ($node): array => [$node->width, $node->height], $input->nodes));
    }

    public function testAStarKeepsItsOwnProportions(): void
    {
        $input = self::input('digraph { node [shape=star, label=""]; a; b [height=2]; c [fixedsize=true, width=2, height=1]; }');

        // As `dot` sizes them: a regular star as big as covers its box, fixed or not.
        $this->assertEqualsWithDelta([[54.0, 51.36], [151.41, 144.0], [144.0, 136.95]], \array_map(static fn ($node): array => [$node->width, $node->height], $input->nodes), 0.01);
    }

    public function testOutlinesAroundANodeGrowIt(): void
    {
        $input = self::input(
            'digraph { node [width=1, height=0.5]; double [shape=doublecircle]; box [shape=box, peripheries=3]; diamond [shape=diamond, peripheries=2]; single [shape=doublecircle, peripheries=1]; point [shape=point, peripheries=2]; }',
        );

        // As `dot` sizes them: each outline 4 outside the one within, along every side — a diamond's slanted ones reaching further.
        $this->assertEqualsWithDelta(
            [[80.0, 80.0], [88.0, 52.0], [89.89, 44.94], [72.0, 72.0], [44.0, 44.0]],
            \array_map(static fn ($node): array => [$node->width, $node->height], $input->nodes),
            0.01,
        );
    }

    public function testClustersClaimNodesOnceAndRankSetsTieTheirs(): void
    {
        $input = self::input(<<<'DOT'
            digraph {
              subgraph plain { subgraph cluster_a { label=A; a; subgraph cluster_b { b } } }
              subgraph cluster_a { c }
              subgraph cluster_c { a; d }
              { rank=same; a; d }
              { rank=bogus; b }
              subgraph empty { rank=min }
            }
            DOT);

        $this->assertSame(
            [
                ['cluster_a',   'A', null],
                ['cluster_b',   'A', 'cluster_a'],
                ['cluster_a#1', '',  null],
                ['cluster_c',   '',  null],
            ],
            \array_map(static fn (LayoutCluster $cluster): array => [$cluster->id, $cluster->label, $cluster->parent], $input->clusters),
        );
        $owners = [];
        foreach ($input->nodes as $node) {
            $owners[$node->id] = $node->cluster;
        }
        // As dot 16.1.0 draws it, a and d leave their clusters for the rank set's level, the top.
        $this->assertSame(['a' => null, 'b' => 'cluster_b', 'c' => 'cluster_a#1', 'd' => null], $owners);
        $this->assertCount(1, $input->rankSets);
        $this->assertSame([RankKind::Same, ['a', 'd']], [$input->rankSets[0]->kind, $input->rankSets[0]->nodes]);
    }

    public function testANodeInARankSetLeavesTheClustersInsideTheOneTheSetIsWrittenIn(): void
    {
        // Measured on dot 16.1.0: x leaves cluster_b for cluster_a, where the set is written; y stays.
        $input = self::input('digraph { subgraph cluster_a { subgraph cluster_b { x; z } y; { rank=same; x; y } } { rank=same; w } w; subgraph cluster_c { w } }');
        $owners = [];
        foreach ($input->nodes as $node) {
            $owners[$node->id] = $node->cluster;
        }

        $this->assertSame(['x' => 'cluster_a', 'z' => 'cluster_b', 'y' => 'cluster_a', 'w' => null], $owners);
        $this->assertFalse($input->newRank);
        // fdp, osage and the other layouts keep every node in its cluster, as dot -Kfdp does.
        $source = Work::run(new DotParser()->parse('graph { {rank=same; a; b} subgraph cluster_x {a; c} a--c }'));
        $this->assertSame('cluster_x', DotLayoutInput::from($source)->nodes[0]->cluster);
        $this->assertNull(DotLayoutInput::forDot($source)->nodes[0]->cluster);
        // A set written after the cluster it empties, unlike one written before, leaves dot 16.1.0 ranking cluster by cluster.
        $this->assertTrue(self::input('digraph { subgraph cluster_e { g } {rank=same; g} }')->emptied);
        $this->assertFalse(self::input('digraph { {rank=same; g} subgraph cluster_e { g } }')->emptied);
        // A set written after the cluster, of any kind, leaves its node ranked there, as dot 16.1.0 ranks it.
        $this->assertSame(['g' => ['cluster_e', null]], self::input('digraph { subgraph cluster_e { g } {rank=same; g} }')->held);
        $this->assertSame([], self::input('digraph { {rank=same; g} subgraph cluster_e { g } }')->held);
        $this->assertSame(['g' => ['cluster_e', null]], self::input('digraph { subgraph cluster_e { g } {rank=min; g} }')->held);
        // A cluster's own rank ties nothing, as dot 16.1.0 draws a above b.
        $this->assertSame([], self::input('digraph { subgraph cluster_a { rank=same; a; b } a->b }')->rankSets);
        // A set written in cluster_y leaves p in cluster_x, which is not inside it, as dot 16.1.0 draws it.
        $sibling = self::input('digraph { subgraph cluster_x { p } subgraph cluster_y { q; { rank=same; p; q } } }');
        $this->assertSame(['cluster_x', 'cluster_y'], [$sibling->nodes[0]->cluster, $sibling->nodes[1]->cluster]);
        // With newrank, dot 16.1.0 draws every node in its own cluster.
        $ranked = self::input('digraph { newrank=true; subgraph cluster_c { w } { rank=same; w } }');
        $this->assertSame([true, 'cluster_c'], [$ranked->newRank, $ranked->nodes[0]->cluster]);
    }

    public function testGlobalOrNoClusterRankLaysOutNoCluster(): void
    {
        $sets = static fn (LayoutGraph $input): array => \array_map(static fn ($set): array => [$set->kind, $set->nodes], $input->rankSets);
        // Measured on dot 16.1.0: no cluster is drawn, and only sets written outside every cluster tie their nodes.
        foreach (['global', 'none'] as $value) {
            $input = self::input("digraph { clusterrank={$value}; subgraph cluster_a { rank=same; x; q; subgraph cluster_b { y } { rank=min; y } } { { rank=max; z } } x->q }");
            $this->assertSame([], $input->clusters, $value);
            $this->assertSame([null, null, null, null], \array_map(static fn ($node): ?string => $node->cluster, $input->nodes), $value);
            $this->assertSame([[RankKind::Max, ['z']]], $sets($input), $value);
        }
        // dot 16.1.0 reads the value as spelt, and lays clusters out under newrank whatever it says.
        $this->assertCount(1, self::input('digraph { clusterrank=GLOBAL; subgraph cluster_a { x } }')->clusters);
        $this->assertCount(1, self::input('digraph { newrank=true; clusterrank=global; subgraph cluster_a { x } }')->clusters);
        // The other layouts keep their clusters.
        $this->assertCount(1, DotLayoutInput::from(Work::run(new DotParser()->parse('graph { clusterrank=global; subgraph cluster_a { x } }')))->clusters);
    }

    public function testOrderingComesFromTheNodeOrTheGraph(): void
    {
        $input = DotLayoutInput::from(Work::run(new DotParser()->parse('digraph { ordering=out; a; b [ordering=in]; c [ordering=sideways]; }')));

        $this->assertSame(['out', 'in', null], \array_map(static fn ($node): ?string => $node->ordering, $input->nodes));
    }

    public function testEdgesWithinAGroupWeighAHundredTimesMore(): void
    {
        $input = self::input('digraph { a [group=g]; b [group=g]; c [group=h]; d; e; a -> b [weight=2]; b -> c; d -> e; }');

        $this->assertSame([200, 1, 1], \array_map(static fn ($edge): int => $edge->weight, $input->edges));
    }

    public function testHugeLengthsWeightsAndSizesAreHeldDown(): void
    {
        $input = self::input('digraph { ranksep="1e300"; nodesep=-5; a [width=inf, height="1e9"]; a -> b [minlen="1e12", weight=99999999]; b -> c [minlen=x, weight=-3] }');

        $this->assertSame([DotLayoutInput::MAX_MINLEN, DotLayoutInput::MAX_WEIGHT], [$input->edges[0]->minLength, $input->edges[0]->weight]);
        $this->assertSame([1, 0], [$input->edges[1]->minLength, $input->edges[1]->weight], 'unreadable falls back to one, negative to zero');
        $this->assertSame([54.0, 72_000.0], [$input->nodes[0]->width, $input->nodes[0]->height]);
        $this->assertSame([72_000.0, 1.44], [$input->spacing->rankGap, $input->spacing->nodeGap]);
    }

    public function testPortsStandAcrossTheirNodesAlongTheOrder(): void
    {
        // A port's end, from the node's centre: a quarter of the way across and up, or past the side.
        $ports = static fn (DotNode $node, string $port, float $width, float $height): ?array => match ($port) {
            'q' => [$width / 4, -$height / 4],
            'far' => [$width, $height],
            default => null,
        };
        $down = DotLayoutInput::from(Work::run(new DotParser()->parse('digraph { a:q -> b:far; a -> b:none; a:q -> b }')), ports: $ports);
        $across = DotLayoutInput::from(Work::run(new DotParser()->parse('digraph { rankdir=LR; a:q -> b }')), ports: $ports);
        $none = self::input('digraph { a:q -> b:far }');

        $this->assertSame([0.25, 0.5], [$down->edges[0]->tailPort, $down->edges[0]->headPort], 'across, as far as the node reaches');
        $this->assertSame([0.0, 0.0], [$down->edges[1]->tailPort, $down->edges[1]->headPort], 'none, or one naming nothing');
        $this->assertSame(0.25, $across->edges[0]->tailPort, 'laid out sideways, up the node, the order running from its far end');
        $this->assertSame([0.0, 0.0], [$none->edges[0]->tailPort, $none->edges[0]->headPort], 'without measuring ports');
    }

    public function testRatioAsksForAnAspect(): void
    {
        $this->assertSame(2.0, self::input('digraph { ratio=2 }')->aspect);
        $this->assertSame(100.0, self::input('digraph { ratio="1e9" }')->aspect);
        $this->assertSame(5.0, self::input('digraph { ratio=fill; size="1,5!" }')->aspect);
        $this->assertSame(1.0, self::input('digraph { ratio=fill; size="3" }')->aspect);
        $this->assertSame([72.0, 360.0], self::input('digraph { ratio=fill; size="1,5!" }')->fill);
        $this->assertNull(self::input('digraph { ratio=2; size="1,5" }')->fill);
        // A size past the largest taken is held down whole, keeping its shape.
        $huge = self::input('digraph { ratio=fill; size="2000,1500" }');
        $this->assertSame([0.75, [72_000.0, 54_000.0]], [$huge->aspect, $huge->fill]);
        foreach (['ratio=fill', 'ratio=fill; size="0,1"', 'ratio=auto; size="1,5"', 'ratio=expand', ''] as $none) {
            $this->assertNull(self::input('digraph { '.$none.' }')->aspect, $none);
        }
    }

    public function testNodesNamedByNumbersKeepTheirNames(): void
    {
        $input = self::input('digraph { 1 -> 2; subgraph cluster_0 { 3 } }');

        $this->assertSame(['1', '2', '3'], \array_map(static fn ($node): string => $node->id, $input->nodes), 'a name, not the number PHP makes of it as a key');
        $this->assertSame(['1', '2'], [$input->edges[0]->from, $input->edges[0]->to]);
    }

    private static function input(string $source): LayoutGraph
    {
        return DotLayoutInput::forDot(Work::run(new DotParser()->parse($source)));
    }
}
