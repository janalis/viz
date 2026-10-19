<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\Model\Activity\Step;
use Janalis\Viz\PlantUML\Source\PlantUmlActivityWriter;
use Janalis\Viz\Tests\Support\ActivitySample;
use PHPUnit\Framework\TestCase;

final class PlantUmlActivityWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenInPlantUmlsActivitySyntax(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Order
            start
            :Read\norder;
            note left : checked
            if (paid?) then (yes)
              :Ship;
            elseif (credit?) then
              :Bill;
            else (no)
              :Cancel;
              end
            endif
            if (gift?) then (yes)
              :Wrap;
            endif
            while (more items?) is (yes)
              :Pack;
            endwhile (no)
            repeat
              :Inspect;
            repeat while (damaged?) is (yes) not (no)
            repeat
            repeat while (slow?)
            fork
              :Email;
            fork again
              :Text;
            end fork
            split
              :Log;
            split again
              :Audit;
              detach
            end split
            fork
              :Ping;
            fork again
            end fork
            partition "Warehouse" {
              :Store;
              partition "Shelf" {
                :Label;
              }
            }
            note right : done
            stop
            @enduml

            PUML, PlantUmlActivityWriter::write(ActivitySample::diagram()));
    }

    public function testAStepOfNoKnownKindIsSkipped(): void
    {
        $this->assertSame("@startuml\n@enduml\n", PlantUmlActivityWriter::write(new ActivityDiagram([new class implements Step {}])));
    }
}
