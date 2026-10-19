<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Language\DotParser;
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
        $this->assertSame(['a' => 'cluster_a', 'b' => 'cluster_b', 'c' => 'cluster_a#1', 'd' => 'cluster_c'], $owners);
        $this->assertCount(1, $input->rankSets);
        $this->assertSame([RankKind::Same, ['a', 'd']], [$input->rankSets[0]->kind, $input->rankSets[0]->nodes]);
    }

    public function testHugeLengthsWeightsAndSizesAreHeldDown(): void
    {
        $input = self::input('digraph { ranksep="1e300"; nodesep=-5; a [width=inf, height="1e9"]; a -> b [minlen="1e12", weight=99999999]; b -> c [minlen=x, weight=-3] }');

        $this->assertSame([DotLayoutInput::MAX_MINLEN, DotLayoutInput::MAX_WEIGHT], [$input->edges[0]->minLength, $input->edges[0]->weight]);
        $this->assertSame([1, 0], [$input->edges[1]->minLength, $input->edges[1]->weight], 'unreadable falls back to one, negative to zero');
        $this->assertSame([54.0, 72_000.0], [$input->nodes[0]->width, $input->nodes[0]->height]);
        $this->assertSame([72_000.0, 1.44], [$input->spacing->rankGap, $input->spacing->nodeGap]);
    }

    private static function input(string $source): LayoutGraph
    {
        return DotLayoutInput::from(Work::run(new DotParser()->parse($source)));
    }
}
