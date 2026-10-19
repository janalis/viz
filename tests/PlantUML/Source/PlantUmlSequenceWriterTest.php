<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\PlantUML\Source\PlantUmlSequenceWriter;
use Janalis\Viz\Tests\Support\SequenceSample;
use PHPUnit\Framework\TestCase;

final class PlantUmlSequenceWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenAsAPlantUmlSequenceDiagram(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Login
            autonumber
            box "Back end" #LightBlue
              database Db
              queue Queue
            end box
            actor User
            boundary "Web 'app'" as Web
            participant end
            User -> Web : log in; #1\nplease
            activate Web
            alt known
              Web ->> Db : lookup
              Db --> Web : row
            else unknown
              Web -->x User : refuse
            end
            par fan out
              Web <-> Queue : job
            else and log
            end
            group
              note over Web, Db : busy
            end
            group maintenance
              note left of end : left
            end
            loop every minute
              create control Worker
              Queue -> Worker : spawn
            end
            critical commit
            else on failure
              Db <<->> Db : rollback
            end
            opt
              Web x->x Queue : either
            end
            break stop
              destroy Worker
              Queue ->x Worker : kill
            end
            == Later ==
            deactivate Web
            ...a while...
            ...
            note right of User : done
            @enduml

            PUML, PlantUmlSequenceWriter::write(SequenceSample::diagram()));
    }

    public function testADelayEndingTheStepsStillComesAfterTheActivationsAndAnIdNeverCollides(): void
    {
        $diagram = new SequenceDiagram(
            [new Participant('p0'), new Participant('a b')],
            [new Box('', ['ghost'])],
            [new class implements \Janalis\Viz\Model\Sequence\Step {}, new Separator('wait', delay: true), new Activation('p0')],
        );

        $this->assertSame("@startuml\nbox \"\"\n  participant ghost\nend box\nparticipant p0\nparticipant \"a b\" as p1\nactivate p0\n...wait...\n@enduml\n", PlantUmlSequenceWriter::write($diagram));
    }
}
