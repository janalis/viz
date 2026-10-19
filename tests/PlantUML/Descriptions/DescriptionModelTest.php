<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Descriptions;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\Note;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;
use Janalis\Viz\PlantUML\PlantUmlReader;
use PHPUnit\Framework\TestCase;

final class DescriptionModelTest extends TestCase
{
    public function testActorsAndUseCasesMakeAUseCaseDiagram(): void
    {
        $diagram = new PlantUmlReader()->read(<<<'PUML'
            @startuml
            title Shop
            left to right direction
            actor "Shopper" as S <<human>>
            :Boss:/ as B
            rectangle Store {
              together {
                (Buy)
              }
              usecase/ "Audit" as A
            }
            node Server
            S --> (Buy) : clicks
            (Buy) ..> (Pay) : <<include>>
            (Refund) ..> (Buy) <<extend>>
            B --|> S
            S <-[hidden]- (Pay)
            S --o B
            note "remember" as N
            N .. S
            note "alone" as Alone

            hide empty description
            note right of (Buy) : popular
            @enduml
            PUML);

        $this->assertInstanceOf(UseCaseDiagram::class, $diagram);
        $this->assertEquals([new Actor('S', 'Shopper', 'human'), new Actor('B', 'Boss', business: true)], $diagram->actors);
        $this->assertEquals([new UseCase('Buy'), new UseCase('A', 'Audit', business: true), new UseCase('Pay'), new UseCase('Refund')], $diagram->useCases);
        $this->assertEquals([new Boundary('Store', '', ['Buy', 'A'])], $diagram->boundaries);
        $this->assertEquals(
            [
                new Association('S', 'Buy', label: 'clicks'),
                new Association('Buy', 'Pay', AssociationKind::Include),
                new Association('Refund', 'Buy', AssociationKind::Extend),
                new Association('B', 'S', AssociationKind::Generalization, head: ArrowHead::Triangle),
                new Association('S', 'B', head: ArrowHead::HollowDiamond, tail: ArrowHead::None),
            ],
            $diagram->associations,
        );
        $this->assertEquals([new Note('remember', 'S'), new Note('alone'), new Note('popular', 'Buy')], $diagram->notes);
        $this->assertSame(['Shop', Direction::LeftRight], [$diagram->title, $diagram->direction]);
    }

    public function testOtherDiagramsMakeAGraph(): void
    {
        $graph = new PlantUmlReader()->read(<<<'PUML'
            @startuml
            node "Web server" as W #back:pink;line:red
            database DB
            queue Q
            card C
            file F
            hexagon H
            () I
            rectangle R
            package P {
              together {
                component Inner
              }
              frame Deep {
                agent A
              }
            }
            W --> DB : reads
            W ..> Q
            W -[bold]-> C
            W -[dotted]- F
            W *-- H
            W --x I
            W <|-- R
            note "free" as N
            note bottom of W : on top
            note on link : linked
            @enduml
            PUML);

        $this->assertInstanceOf(Graph::class, $graph);
        $shapes = [];
        foreach ($graph->nodes as $node) {
            $shapes[$node->id] = $node->shape;
        }
        $this->assertSame(
            [
                'W' => Shape::Box,
                'DB' => Shape::Cylinder,
                'Q' => Shape::Cylinder,
                'C' => Shape::Rounded,
                'F' => Shape::Note,
                'H' => Shape::Hexagon,
                'I' => Shape::Circle,
                'R' => Shape::Box,
                'Inner' => Shape::Box,
                'A' => Shape::Box,
                'N' => Shape::Note,
                'note#1' => Shape::Note,
            ],
            $shapes,
        );
        $this->assertEquals(new Node('W', 'Web server', Shape::Box, '#ffc0cb', '#ff0000'), $graph->nodes[0]);
        $this->assertEquals(
            [
                new Edge('W', 'DB', 'reads'),
                new Edge('W', 'Q', style: LineStyle::Dashed),
                new Edge('W', 'C', style: LineStyle::Bold),
                new Edge('W', 'F', head: ArrowHead::None, style: LineStyle::Dotted),
                new Edge('W', 'H', head: ArrowHead::None, tail: ArrowHead::Diamond),
                new Edge('W', 'I', head: ArrowHead::Cross),
                new Edge('W', 'R', head: ArrowHead::None, tail: ArrowHead::Triangle),
                new Edge('note#1', 'W', head: ArrowHead::None, style: LineStyle::Dotted),
            ],
            $graph->edges,
        );
        $this->assertEquals([new Cluster('P', '', ['Inner'], [new Cluster('Deep', '', ['A'])])], $graph->clusters);
    }
}
