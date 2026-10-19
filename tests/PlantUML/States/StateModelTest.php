<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\States;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\NoteSide;
use Janalis\Viz\Model\State\Region;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;
use Janalis\Viz\PlantUML\PlantUmlReader;
use PHPUnit\Framework\TestCase;

final class StateModelTest extends TestCase
{
    public function testStatesRegionsTransitionsAndNotesMapOntoTheSharedModel(): void
    {
        $diagram = new PlantUmlReader()->read(<<<'PUML'
            @startuml
            title Machine
            left to right direction
            [*] --> Active
            state "Running hot" as Active {
              [*] --> A
              --
              A2 --> [H]
              A2 --> [H*]
            }
            Active : busy
            state Pick <<choice>>
            state Split <<fork>>
            state Merge <<join>>
            Active --> Pick : check
            Pick --> ==bar==
            ==bar== --> [*]
            note left of Active : hot
            note right of Pick : picks
            note bottom of Merge : below
            @enduml
            PUML);

        $this->assertInstanceOf(StateDiagram::class, $diagram);
        $this->assertSame(['Machine', Direction::LeftRight], [$diagram->title, $diagram->direction]);
        $this->assertEquals(
            [
                new State('*start*', kind: StateKind::Initial),
                new State(
                    'Active',
                    'Running hot',
                    description: ['busy'],
                    regions: [
                        new Region([new State('*start*Active', kind: StateKind::Initial), new State('A')]),
                        new Region([new State('A2'), new State('*h*Active#1', kind: StateKind::History), new State('*h**Active#1', kind: StateKind::DeepHistory)]),
                    ],
                ),
                new State('Pick', kind: StateKind::Choice),
                new State('Split', kind: StateKind::Fork),
                new State('Merge', kind: StateKind::Join),
                new State('==bar', kind: StateKind::Fork),
                new State('*end*', kind: StateKind::Final),
            ],
            $diagram->states,
        );
        $this->assertEquals(new Transition('Active', 'Pick', 'check'), $diagram->transitions[4]);
        $this->assertEquals([new Note('hot', 'Active', NoteSide::Left), new Note('picks', 'Pick'), new Note('below', 'Merge')], $diagram->notes);
    }
}
