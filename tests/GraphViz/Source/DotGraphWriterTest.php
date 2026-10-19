<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Source;

use Janalis\Viz\GraphViz\Source\DotGraphWriter;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class DotGraphWriterTest extends TestCase
{
    public function testEveryPartOfTheModelIsWritten(): void
    {
        $this->assertSame(<<<'DOT'
            digraph "system" {
                rankdir=LR;
                node [shape=box];
                label="Sample \"system\"";
                labelloc=t;
                subgraph cluster_0 {
                    label="Back end";
                    "api" [label="API \"v2\"", style="rounded,filled", fillcolor="#ffcc00", color="#333333"];
                    "missing";
                    subgraph cluster_1 {
                        label="Data";
                        "db" [label="Store", shape=cylinder];
                    }
                }
                "end" [label="Two\nlines", shape=diamond];
                "web client" [label="web client", style="rounded"];
                "queue" [label="Q", shape=hexagon];
                "cron" [label="Cron", shape=circle];
                "etl" [label="ETL", shape=parallelogram];
                "docs" [label="Docs", shape=note];
                "user" [label="User", shape=ellipse];
                "box" [label="Box", color="red"];
                "web client" -> "api" [label="calls"];
                "api" -> "db" [style=dashed];
                "api" -> "queue" [style=dotted, arrowhead=dot];
                "queue" -> "end" [style=bold, arrowhead=tee, arrowtail=normal, dir=both];
                "cron" -> "etl" [arrowhead=none];
                "etl" -> "ghost";
                "user" -> "docs" [style=bold, arrowhead=none, arrowtail=dot, dir=both];
                "box" -> "user" [style=dotted, arrowhead=none, arrowtail=tee, dir=both];
            }

            DOT, DotGraphWriter::write(Sample::graph(), 'system'));
    }

    public function testAnEmptyGraphIsStillAGraph(): void
    {
        $this->assertSame("digraph \"G\" {\n    rankdir=TB;\n    node [shape=box];\n}\n", DotGraphWriter::write(new Graph()));
    }

    public function testBackslashesAndCarriageReturnsAreEscaped(): void
    {
        $this->assertStringContainsString('"a\\\\b" [label="x\\ny"];', DotGraphWriter::write(new Graph([new Node('a\\b', "x\r\ny")])));
    }

    public function testCompartmentsMakeARecordAndUmlHeadsKeepTheirShape(): void
    {
        $graph = new Graph(
            [new Node('A', 'A<T>', compartments: [['+x : {int}'], ['go() | stop']], fill: '#eeeeee', stroke: '#333333'), new Node('B', compartments: [[]])],
            [new Edge('A', 'B', head: ArrowHead::Triangle, tail: ArrowHead::Diamond), new Edge('B', 'A', head: ArrowHead::HollowDiamond)],
        );

        $dot = DotGraphWriter::write($graph);

        $this->assertStringContainsString('"A" [label="{A\\<T\\>|+x : \\{int\\}\\l|go() \\| stop\\l}", shape=record, style="filled", fillcolor="#eeeeee", color="#333333"];', $dot);
        $this->assertStringContainsString('"B" [label="{B|}", shape=record];', $dot);
        $this->assertStringContainsString('[arrowhead=empty, arrowtail=diamond, dir=both]', $dot);
        $this->assertStringContainsString('[arrowhead=odiamond]', $dot);
    }
}
