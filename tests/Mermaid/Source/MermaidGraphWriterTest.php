<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\Source\MermaidGraphWriter;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Tests\Mermaid\NativeFixtureScene;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class MermaidGraphWriterTest extends TestCase
{
    public function testEveryPartOfTheModelIsWritten(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Sample \"system\""
            ---
            flowchart LR
                subgraph c0 ["Back end"]
                    api("API #quot;v2#quot;")
                    missing
                    subgraph c1 ["Data"]
                        db[("Store")]
                    end
                end
                n0{"Two<br>lines"}
                n1(["web client"])
                queue{{"Q"}}
                cron(("Cron"))
                etl[/"ETL"/]
                docs@{ shape: doc, label: "Docs" }
                user(("User"))
                box["Box"]
                n1 -->|"calls"| api
                api -.-> db
                api -.-o queue
                queue <==x n0
                cron --- etl
                etl --> ghost
                user o=== docs
                box x-.- user
                style api fill:#ffcc00,stroke:#333333
                style box stroke:red

            MMD, MermaidGraphWriter::write(Sample::graph()));
    }

    public function testAGeneratedIdentifierNeverTakesOneAlreadyUsed(): void
    {
        $graph = new Graph([new Node('a b'), new Node('n0'), new Node('n1')], [new Edge('a b', 'n0')]);

        $this->assertSame("flowchart TB\n    n2[\"a b\"]\n    n0[\"n0\"]\n    n1[\"n1\"]\n    n2 --> n0\n", MermaidGraphWriter::write($graph));
    }

    public function testAnUnlabelledClusterKeepsABlankTitle(): void
    {
        $this->assertStringContainsString('subgraph c0 [" "]', MermaidGraphWriter::write(new Graph([new Node('a')], clusters: [new \Janalis\Viz\Model\Graph\Cluster('x', '', ['a'])])));
    }

    public function testTheWrittenSourceIsReadBackByTheNativeRenderer(): void
    {
        $scene = NativeFixtureScene::render(MermaidGraphWriter::write(Sample::graph()));

        $labels = \array_map(static fn ($node): string => $node->label, $scene->graph->nodes);
        foreach (['API "v2"', 'Store', 'Q', 'Cron', 'ETL', 'Docs', 'User', 'Box', 'web client', 'ghost'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertSame(8, $scene->graph->edgeCount());
    }

    public function testEveryLinkShapeIsWritten(): void
    {
        $graph = new Graph([new Node('a'), new Node('b')], [
            new Edge('a', 'b', tail: ArrowHead::Arrow),
            new Edge('a', 'b', head: ArrowHead::None, tail: ArrowHead::Cross),
            new Edge('a', 'b', head: ArrowHead::Circle, style: LineStyle::Dotted),
        ]);

        $this->assertStringContainsString("    a <--> b\n    a x--- b\n    a -.-o b\n", MermaidGraphWriter::write($graph));
    }

    public function testKeywordsAreRenamedWhateverTheirCase(): void
    {
        $this->assertStringContainsString('n0["End"]', MermaidGraphWriter::write(new Graph([new Node('End')])));
    }

    public function testNestedClusterMembersThatAreNoNodesStillGetAnIdentifier(): void
    {
        $graph = new Graph(clusters: [new Cluster('o', 'O', [], [new Cluster('i', 'I', ['a b'])])]);

        $this->assertStringContainsString("        subgraph c1 [\"I\"]\n            n0\n", MermaidGraphWriter::write($graph));
    }

    public function testATitleKeepsItsUnicodeAndSlashesReadable(): void
    {
        $this->assertStringStartsWith("---\ntitle: \"Data/flow — é\"\n---\n", MermaidGraphWriter::write(new Graph(title: 'Data/flow — é')));
    }
}
