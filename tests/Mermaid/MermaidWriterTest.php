<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\Graph;
use Janalis\Viz\Diagram\Node;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class MermaidWriterTest extends TestCase
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

            MMD, MermaidWriter::write(Sample::graph()));
    }

    public function testAGeneratedIdentifierNeverTakesOneAlreadyUsed(): void
    {
        $graph = new Graph([new Node('a b'), new Node('n0'), new Node('n1')], [new Edge('a b', 'n0')]);

        $this->assertSame("flowchart TB\n    n2[\"a b\"]\n    n0[\"n0\"]\n    n1[\"n1\"]\n    n2 --> n0\n", MermaidWriter::write($graph));
    }

    public function testAnUnlabelledClusterKeepsABlankTitle(): void
    {
        $this->assertStringContainsString('subgraph c0 [" "]', MermaidWriter::write(new Graph([new Node('a')], clusters: [new \Janalis\Viz\Diagram\Cluster('x', '', ['a'])])));
    }

    public function testTheWrittenSourceIsReadBackByTheNativeRenderer(): void
    {
        $scene = NativeFixtureScene::render(MermaidWriter::write(Sample::graph()));

        $labels = \array_map(static fn ($node): string => $node->label, $scene->graph->nodes);
        foreach (['API "v2"', 'Store', 'Q', 'Cron', 'ETL', 'Docs', 'User', 'Box', 'web client', 'ghost'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertSame(8, $scene->graph->edgeCount());
    }
}
