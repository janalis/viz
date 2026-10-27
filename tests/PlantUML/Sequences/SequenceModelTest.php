<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Sequences;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\NotePlacement;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\ParticipantKind;
use Janalis\Viz\Model\Sequence\Section;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\PlantUML\PlantUmlReader;
use PHPUnit\Framework\TestCase;

final class SequenceModelTest extends TestCase
{
    public function testEventsMapOntoTheSharedModel(): void
    {
        $diagram = new PlantUmlReader()->read(<<<'PUML'
            @startuml
            title Login
            autonumber
            box "Back" #LightBlue
              database Db
            end box
            actor "The user" as User
            participant Other <<x>>
            User -> Db : query
            activate Db
            Db -->> User : rows
            deactivate Db
            User x->x Db
            User ->o Db
            note left of User : left
            note right of Db : right
            note over User, Db : over
            / note over User : row
            note across : all
            [-> User : from outside
            User -> Db
            note left : before
            note right : after
            note top : above
            alt known
              User -> Db
              loop again
                Db -> Db : self
              end
            else unknown
            end
            group
            end
            group custom [label]
            end
            create Late
            User -> Late
            destroy Late
            == Later ==
            ... a while ...
            |||
            ref over User, Db : details
            @enduml
            PUML);

        $this->assertInstanceOf(SequenceDiagram::class, $diagram);
        $this->assertEquals(
            [new Participant('Db', kind: ParticipantKind::Database), new Participant('User', 'The user', ParticipantKind::Actor), new Participant('Other'), new Participant('Late')],
            $diagram->participants,
        );
        $this->assertEquals([new Box('Back', ['Db'], 'LightBlue')], $diagram->boxes);
        $this->assertSame(['Login', true], [$diagram->title, $diagram->autonumber]);
        $this->assertEquals(
            [
                new Message('User', 'Db', 'query'),
                new Activation('Db'),
                new Message('Db', 'User', 'rows', MessageHead::Open, dashed: true),
                new Activation('Db', active: false),
                new Message('User', 'Db', head: MessageHead::Cross, both: true),
                new Message('User', 'Db'),
                new Note('left', NotePlacement::Left, ['User']),
                new Note('right', NotePlacement::Right, ['Db']),
                new Note('over', NotePlacement::Over, ['User', 'Db']),
                new Note('row', NotePlacement::Over, ['User']),
                new Note('all', NotePlacement::Over, ['Db', 'User', 'Other', 'Late']),
                new Message('User', 'Db'),
                new Note('before', NotePlacement::Left, ['User']),
                new Note('after', NotePlacement::Right, ['Db']),
                new Note('above', NotePlacement::Over, ['User', 'Db']),
                new Fragment(FragmentKind::Alt, [
                    new Section('known', [new Message('User', 'Db'), new Fragment(FragmentKind::Loop, [new Section('again', [new Message('Db', 'Db', 'self')])])]),
                    new Section('unknown', []),
                ]),
                new Fragment(FragmentKind::Group, [new Section('', [])]),
                new Fragment(FragmentKind::Group, [new Section('label', [])]),
                new Lifecycle('Late'),
                new Message('User', 'Late'),
                new Lifecycle('Late', create: false),
                new Separator('Later'),
                new Separator('a while', delay: true),
                new Note('details', NotePlacement::Over, ['User', 'Db']),
            ],
            $diagram->steps,
        );
    }

    public function testANoteAcrossNobodyIsNoSequenceDiagram(): void
    {
        // As PlantUML, which fails on it.
        $this->expectExceptionMessage('A sequence diagram with no participant');

        new PlantUmlReader()->read("@startuml\nnote across : nobody\n@enduml");
    }
}
