<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\Justify;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotNodesTest extends TestCase
{
    public function testTextsExpandTheirObjectsNames(): void
    {
        $graph = self::graph('digraph G { label="\G"; subgraph cluster_x { label="in \G"; a } a [fontsize=900, fontcolor=red]; a -> b [label="\E \T \H", fontsize=x]; }');

        $this->assertSame([['a', Justify::Center]], DotNodes::text($graph, $graph->nodes['a'])->lines);
        $this->assertSame(500.0, DotNodes::text($graph, $graph->nodes['a'])->fontSize);
        $this->assertSame('#ff0000', DotNodes::text($graph, $graph->nodes['a'])->color);
        $this->assertSame([['a->b a b', Justify::Center]], DotNodes::edgeText($graph, $graph->edges[0])->lines);
        $this->assertSame(14.0, DotNodes::edgeText($graph, $graph->edges[0])->fontSize);
        $this->assertSame([['in cluster_x', Justify::Center]], DotNodes::clusterText($graph->subgraphs[0])->lines);
        $this->assertSame([['G', Justify::Center]], DotNodes::graphText($graph)->lines);
        $undirected = self::graph('graph { a -- b [label="\E"]; }');
        $this->assertSame([['a--b', Justify::Center]], DotNodes::edgeText($undirected, $undirected->edges[0])->lines);
    }

    public function testSizesWrapTheLabelInItsMarginAndShape(): void
    {
        $graph = self::graph(
            'digraph { box [shape=box]; ellipse; circle [shape=circle]; regular [shape=box, regular=true]; bare [shape=plaintext]; margin [shape=box, margin="0.5"]; odd [shape=box, margin="x,y"]; }',
        );
        $cell = Units::cell(14.0);
        $line = Units::line(14.0);

        $this->assertEqualsWithDelta([(3 * $cell) + 16, $line + 8], DotNodes::size($graph, $graph->nodes['box']), 1e-9);
        $this->assertEqualsWithDelta([((7 * $cell) + 16) * \M_SQRT2, ($line + 8) * \M_SQRT2], DotNodes::size($graph, $graph->nodes['ellipse']), 1e-9);
        [$width, $height] = DotNodes::size($graph, $graph->nodes['circle']);
        $this->assertSame($width, $height);
        [$width, $height] = DotNodes::size($graph, $graph->nodes['regular']);
        $this->assertSame($width, $height);
        $this->assertEqualsWithDelta([(4 * $cell) + 16, $line + 8], DotNodes::size($graph, $graph->nodes['bare']), 1e-9);
        $this->assertEqualsWithDelta([(6 * $cell) + 72, $line + 72], DotNodes::size($graph, $graph->nodes['margin']), 1e-9);
        $this->assertEqualsWithDelta([(3 * $cell) + 16, $line + 8], DotNodes::size($graph, $graph->nodes['odd']), 1e-9);
    }

    public function testANodeWithNoLabelToShowTakesNoRoomForItsMargin(): void
    {
        $graph = self::graph('digraph { circle [shape=circle, label=""]; box [shape=box, label=""]; diamond [shape=diamond, label=""]; space [shape=box, label=" "]; }');

        // As `dot` sizes them: as small as their width and height let them be, a label of a space still taken.
        $this->assertSame([[0.0, 0.0], [0.0, 0.0], [0.0, 0.0]], [
            DotNodes::size($graph, $graph->nodes['circle']),
            DotNodes::size($graph, $graph->nodes['box']),
            DotNodes::size($graph, $graph->nodes['diamond']),
        ]);
        $this->assertGreaterThan(15.84, DotNodes::size($graph, $graph->nodes['space'])[0]);
    }

    public function testShapesGrowRoundTheirLabelAsDotIsSeenToGrowThem(): void
    {
        $size = static function (string $shape, int $height): array {
            $graph = self::graph("digraph { a [shape={$shape}, label=<<TABLE FIXEDSIZE=\"TRUE\" WIDTH=\"100\" HEIGHT=\"{$height}\"><TR><TD></TD></TR></TABLE>>] }");

            return DotNodes::size($graph, $graph->nodes['a']);
        };

        // As measured on `dot`'s output: an n-sided polygon √2 / cos(π/n) times its label, or fitted to the 36 it keeps at least.
        $this->assertEqualsWithDelta(
            [[189.42, 45.72], [177.57, 42.86], [232.0, 56.0], [240.52, 56.0], [174.11, 36.0], [213.24, 36.0], [160.58, 36.0]],
            [
                $size('hexagon', height: 20),
                $size('octagon', height: 20),
                $size('diamond', height: 20),
                $size('parallelogram', height: 20),
                $size('hexagon', height: 15),
                $size('diamond', height: 15),
                $size('egg', height: 15),
            ],
            0.01,
        );
    }

    public function testRecordSizesKeepToTheLargestSizeTaken(): void
    {
        $graph = Work::run(new DotParser()->parse('digraph { a [shape=record, label="'.\str_repeat('x|', times: 20_000).'"]; b [shape=record, label="{bad"] }'));
        [$width] = DotNodes::size($graph, $graph->nodes['a']);
        [$named] = DotNodes::size($graph, $graph->nodes['b']);

        $this->assertSame(DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS, $width);
        $this->assertGreaterThan(16.0, $named, 'a bad label sizes the name');
    }

    public function testShapesTakeTheirPolygonAttributes(): void
    {
        $graph = self::graph('digraph { a [shape=polygon, sides=3, peripheries=2]; b [shape=polygon, sides=x]; }');

        $shape = DotNodes::shape($graph->nodes['a'], 40.0, 40.0);
        $this->assertCount(3, $shape->outline);
        $this->assertCount(1, $shape->peripheries);
        $this->assertCount(4, DotNodes::shape($graph->nodes['b'], 40.0, 40.0)->outline);
        $this->assertSame(2.5, DotNodes::number('2.5', 1.0));
        $this->assertSame(1.0, DotNodes::number('', 1.0));
    }

    private static function graph(string $source): DotGraph
    {
        return Work::run(new DotParser()->parse($source));
    }
}
