<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Diagram\ArrowHead;
use Janalis\Viz\Diagram\Direction;
use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\LineStyle;
use Janalis\Viz\Diagram\Node;
use Janalis\Viz\Diagram\Shape;
use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class MermaidReaderTest extends TestCase
{
    public function testAFlowchartReadsIntoTheSharedModel(): void
    {
        $graph = MermaidReader::read(<<<'MMD'
            ---
            title: Shapes
            ---
            flowchart RL
              a[Box] --> b(Round)
              b --- c([Stadium])
              c -.-> d((Circle))
              d ==> e{Diamond}
              e --o f{{Hex}}
              f --x g[/Para/]
              g <--> h[(Cyl)]
              h -->|lbl| i>Asym]
              i o--o j(((Dbl)))
              k@{ shape: doc, label: "Doc" } ~~~ l
              m[\Lean\] x--x n(Ellipse)
              n@{ shape: ellipse }
              style a fill:#f9f,stroke:#333
            MMD);

        $this->assertSame(Direction::RightLeft, $graph->direction);
        $this->assertSame('Shapes', $graph->title);
        $shapes = [];
        foreach ($graph->nodes as $node) {
            $shapes[$node->id] = $node->shape;
        }
        $this->assertSame([
            'a' => Shape::Box, 'b' => Shape::Rounded, 'c' => Shape::Stadium, 'd' => Shape::Circle, 'e' => Shape::Diamond,
            'f' => Shape::Hexagon, 'g' => Shape::Parallelogram, 'h' => Shape::Cylinder, 'i' => Shape::Box, 'j' => Shape::Circle,
            'k' => Shape::Note, 'l' => Shape::Box, 'm' => Shape::Parallelogram, 'n' => Shape::Ellipse,
        ], $shapes);
        $this->assertEquals(new Node('a', 'Box', Shape::Box, '#ff99ff', '#333333'), $graph->nodes[0]);
        $this->assertNull($graph->node('l')?->label);
        $this->assertEquals([
            new Edge('a', 'b'),
            new Edge('b', 'c', head: ArrowHead::None),
            new Edge('c', 'd', style: LineStyle::Dotted),
            new Edge('d', 'e', style: LineStyle::Bold),
            new Edge('e', 'f', head: ArrowHead::Circle),
            new Edge('f', 'g', head: ArrowHead::Cross),
            new Edge('g', 'h', tail: ArrowHead::Arrow),
            new Edge('h', 'i', 'lbl'),
            new Edge('i', 'j', head: ArrowHead::Circle, tail: ArrowHead::Circle),
            new Edge('k', 'l', head: ArrowHead::None),
            new Edge('m', 'n', head: ArrowHead::Cross, tail: ArrowHead::Cross),
        ], $graph->edges);
    }

    public function testSubgraphsNestAndOwnTheirInnermostNodes(): void
    {
        $graph = MermaidReader::read("flowchart TD\n  subgraph S1 [Outer]\n    k\n    subgraph S2\n      l\n    end\n  end\n  subgraph S3\n  end\n  k --> l --> z");

        $this->assertSame(Direction::TopBottom, $graph->direction);
        $this->assertSame('', $graph->title);
        $this->assertCount(2, $graph->clusters);
        [$outer, $empty] = $graph->clusters;
        $this->assertSame(['S1', 'Outer', ['k']], [$outer->id, $outer->label, $outer->nodes]);
        $this->assertCount(1, $outer->clusters);
        $this->assertSame(['S2', 'S2', ['l'], []], [$outer->clusters[0]->id, $outer->clusters[0]->label, $outer->clusters[0]->nodes, $outer->clusters[0]->clusters]);
        $this->assertSame(['S3', [], []], [$empty->id, $empty->nodes, $empty->clusters]);
        $this->assertSame(['z'], \array_map(static fn (Node $node): string => $node->id, $graph->looseNodes()));
    }

    public function testNumericIdentifiersStayStrings(): void
    {
        $graph = MermaidReader::read("flowchart LR\n  subgraph 7\n    1 --> 2\n  end");

        $this->assertSame(['1', '2'], \array_map(static fn (Node $node): string => $node->id, $graph->nodes));
        $this->assertSame(['1', '2'], $graph->clusters[0]->nodes);
        $this->assertSame('7', $graph->clusters[0]->id);
    }

    public function testWhatTheWriterWritesReadsBackTheSame(): void
    {
        $sample = Sample::graph();
        $graph = MermaidReader::read(MermaidWriter::write($sample));

        $this->assertSame($sample->title, $graph->title);
        $this->assertSame($sample->direction, $graph->direction);
        $this->assertSame(\count($sample->edges), \count($graph->edges));
        $this->assertSame(\array_map(static fn (Edge $edge): array => [$edge->label, $edge->head, $edge->tail], $sample->edges), \array_map(static fn (Edge $edge): array => [$edge->label, $edge->head, $edge->tail], $graph->edges));
        $this->assertSame(['API "v2"', '#ffcc00', '#333333'], [$graph->node('api')?->label, $graph->node('api')?->fill, $graph->node('api')?->stroke]);
    }

    public function testAnotherDiagramTypeIsASyntaxError(): void
    {
        $this->expectException(MermaidSyntaxError::class);

        MermaidReader::read("sequenceDiagram\n  A->>B: hi");
    }
}
