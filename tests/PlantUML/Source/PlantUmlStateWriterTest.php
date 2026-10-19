<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\PlantUML\Source\PlantUmlStateWriter;
use Janalis\Viz\Tests\Support\StateSample;
use PHPUnit\Framework\TestCase;

final class PlantUmlStateWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenAsAPlantUmlStateDiagram(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Machine
            left to right direction
            state Idle
            Idle : waiting
            Idle : two
            Idle : lines
            state "Busy 'now'" as Busy {
              state Load
              state history <<history>>
              [*] --> Load
              --
              state Spin
              state deep <<history*>>
              Spin --> deep
            }
            state Paused {
              state Frozen
              [*] --> Frozen
            }
            state pick <<choice>>
            state split <<fork>>
            state merge <<join>>
            state state
            [*] --> Idle
            Idle --> Busy : go
            Busy --> pick : two\nlines
            pick --> [*]
            note left of Idle : beside
            note right of Busy
            several
            lines
            end note
            note "floating 'free'" as N2
            @enduml

            PUML, PlantUmlStateWriter::write(StateSample::diagram()));
    }

    public function testAnIdPlantUmlWouldMisreadIsRenamed(): void
    {
        $diagram = new StateDiagram([new \Janalis\Viz\Model\State\State('s0'), new \Janalis\Viz\Model\State\State('two words')], direction: Direction::BottomTop);

        $this->assertSame("@startuml\nstate s0\nstate \"two words\" as s1\n@enduml\n", PlantUmlStateWriter::write($diagram));
    }
}
