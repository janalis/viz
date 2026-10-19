<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Source;

use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotTool;
use Janalis\Viz\GraphViz\Source\DotGraphReader;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Tests\Support\RandomDot;
use PHPUnit\Framework\TestCase;

final class DotModelReaderTest extends TestCase
{
    public function testTheResolvedGraphMapsOntoTheSharedModel(): void
    {
        $graph = self::read(<<<'DOT'
            digraph G {
              rankdir=lr; label="\G says\nhi";
              x [shape=diamond, style="rounded, filled", fillcolor=red, color=blue];
              y [label="first\nsecond\lthird\rfourth \N", style=rounded];
              z [shape=tab, label=""]; w [shape=point, label=w];
              x -> y [label="l\nm", style="dashed,bold", arrowhead=odot, arrowtail=tee, dir=both];
              z -> x [style=dotted, dir=back, arrowtail=none];
              z -> w [style=bold, dir=none];
              w -> z [arrowhead=lvee, style=tapered];
            }
            DOT);

        $this->assertSame([Direction::LeftRight, "G says\nhi"], [$graph->direction, $graph->title]);
        $this->assertEquals(
            [
                new Node('x', null, Shape::Diamond, 'red', 'blue'),
                new Node('y', "first\nsecond\nthird\nfourth y", Shape::Rounded),
                new Node('z', null, Shape::Note),
                new Node('w', null, Shape::Circle),
            ],
            $graph->nodes,
        );
        $this->assertEquals(
            [
                new Edge('x', 'y', "l\nm", ArrowHead::Circle, ArrowHead::Cross, LineStyle::Dashed),
                new Edge('z', 'x', head: ArrowHead::None, tail: ArrowHead::None, style: LineStyle::Dotted),
                new Edge('z', 'w', head: ArrowHead::None, style: LineStyle::Bold),
                new Edge('w', 'z'),
            ],
            $graph->edges,
        );
    }

    public function testAnUndirectedGraphHasNoArrowsUnlessAsked(): void
    {
        $graph = self::read('graph { a -- b; b -- c [dir=forward] }');

        $this->assertSame([ArrowHead::None, ArrowHead::Arrow], [$graph->edges[0]->head, $graph->edges[1]->head]);
    }

    public function testANodeBelongsToTheFirstClusterClaimingItAndThereToItsInnermost(): void
    {
        $graph = self::read(<<<'DOT'
            digraph {
              subgraph plain { subgraph cluster_a { label="A \G"; a; subgraph cluster_b { b } } }
              subgraph cluster_c { a; c; subgraph inner { subgraph cluster_d { d; b } } }
            }
            DOT);

        $this->assertSame(['cluster_a', 'A cluster_a', ['a']], [$graph->clusters[0]->id, $graph->clusters[0]->label, $graph->clusters[0]->nodes]);
        $this->assertSame(['b'], $graph->clusters[0]->clusters[0]->nodes);
        $this->assertSame(['c'], $graph->clusters[1]->nodes, 'a is cluster_a\'s already');
        $this->assertSame(['d'], $graph->clusters[1]->clusters[0]->nodes, 'a plain subgraph lends its clusters; b is cluster_b\'s');
    }

    public function testTheDispatcherReadsDotWithNoTool(): void
    {
        $reader = new DotReader();

        $this->assertSame([Family::Graph], $reader->families());
        $this->assertInstanceOf(Graph::class, $reader->read('digraph { a }'));
    }

    public function testRandomGraphsReadAsDotItselfResolvesThem(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        $tool = new DotTool();
        $compared = 0;
        for ($seed = 0; $seed < 200; ++$seed) {
            $source = RandomDot::source($seed);
            try {
                $expected = DotGraphReader::read($tool->json($source));
            } catch (\Throwable) {
                // dot sometimes cannot lay a random graph out; the language is not in question.
                continue;
            }
            $this->assertEquals($expected, new DotReader()->read($source), 'seed '.$seed);
            ++$compared;
        }
        $this->assertGreaterThan(190, $compared);
    }

    private static function read(string $source): Graph
    {
        $graph = new DotReader()->read($source);
        self::assertInstanceOf(Graph::class, $graph);

        return $graph;
    }
}
