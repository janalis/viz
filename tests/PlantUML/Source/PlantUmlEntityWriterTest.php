<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\Model\Entity\Entity;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Group;
use Janalis\Viz\PlantUML\Source\PlantUmlEntityWriter;
use Janalis\Viz\Tests\Support\EntitySample;
use PHPUnit\Framework\TestCase;

final class PlantUmlEntityWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenAsPlantUmlEntities(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Shop
            left to right direction
            package "Sales" {
              package "items" {
                entity "LINE ITEM" as e0
              }
              entity ORDER {
                * number : int <<PK>> <<FK>> <<UK>>
              }
            }
            entity "Client 'VIP'" as CUSTOMER {
              * id : string <<PK>>
              full name : string // legal\nname
            }
            entity PRODUCT
            CUSTOMER ||--o{ ORDER : places
            ORDER |o..|{ e0 : contains\nlines
            PRODUCT }|--o| e0
            PRODUCT }o--|| PRODUCT
            @enduml

            PUML, PlantUmlEntityWriter::write(EntitySample::diagram()));
    }

    public function testAGroupNamesAnEntityTheDiagramDoesNotListAndAnAliasNeverTakesAnIdInUse(): void
    {
        $diagram = new EntityDiagram([new Entity('e0'), new Entity('a b')], groups: [new Group('g', entities: ['ghost'])]);

        $this->assertSame("@startuml\npackage \"g\" {\n  entity ghost\n}\nentity e0\nentity \"a b\" as e1\n@enduml\n", PlantUmlEntityWriter::write($diagram));
    }
}
