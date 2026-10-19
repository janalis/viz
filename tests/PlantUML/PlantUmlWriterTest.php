<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\Diagram\Cluster;
use Janalis\Viz\Diagram\Direction;
use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\Graph;
use Janalis\Viz\Diagram\Node;
use Janalis\Viz\PlantUML\PlantUmlWriter;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class PlantUmlWriterTest extends TestCase
{
    public function testEveryPartOfTheModelIsWritten(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            left to right direction
            title Sample &#34;system&#34;
            rectangle "Back end" as c0 {
              card "API &#34;v2&#34;" as n0 #ffcc00;line:333333
              rectangle "Data" as c1 {
                database "Store" as n1
              }
            }
            rectangle "Two\nlines" as n2
            card "web client" as n3
            hexagon "Q" as n4
            circle "Cron" as n5
            rectangle "ETL" as n6
            file "Docs" as n7
            usecase "User" as n8
            rectangle "Box" as n9 #white;line:red
            rectangle "ghost" as n10
            n3 --> n0 : calls
            n0 -[dashed]-> n1
            n0 -[dotted]-o n4
            n4 <-[bold]-+ n2
            n5 -- n6
            n6 --> n10
            n8 o-[bold]- n7
            n9 +-[dotted]- n8
            @enduml

            PUML, PlantUmlWriter::write(Sample::graph()));
    }

    public function testOnlyHorizontalDirectionsTurnTheLayout(): void
    {
        $this->assertSame("@startuml\nleft to right direction\n@enduml\n", PlantUmlWriter::write(new Graph(direction: Direction::RightLeft)));
        $this->assertSame("@startuml\n@enduml\n", PlantUmlWriter::write(new Graph(direction: Direction::BottomTop)));
    }

    public function testAFillWithoutAStrokeOmitsTheLine(): void
    {
        $this->assertStringContainsString('rectangle "a" as n0 #pink'."\n", PlantUmlWriter::write(new Graph([new Node('a', fill: 'pink')])));
    }

    public function testDeepClustersIndentByDepthAndSkipMembersThatAreNoNodes(): void
    {
        $graph = new Graph(
            [new Node('a'), new Node('b')],
            [new Edge('ghost', 'a')],
            [new Cluster('x', 'X', [], [new Cluster('y', 'Y', [], [new Cluster('z', 'Z', ['missing', 'a', 'b'])])])],
        );

        $this->assertSame(<<<'PUML'
            @startuml
            rectangle "X" as c0 {
              rectangle "Y" as c1 {
                rectangle "Z" as c2 {
                  rectangle "a" as n0
                  rectangle "b" as n1
                }
              }
            }
            rectangle "ghost" as n2
            n2 --> n0
            @enduml

            PUML, PlantUmlWriter::write($graph));
    }
}
