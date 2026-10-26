<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Sequences;

use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Sequences\AutoNumber;
use Janalis\Viz\PlantUML\Sequences\PlantUmlSequence;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceNotes;
use Janalis\Viz\PlantUML\Sequences\SequenceParticipant;
use Janalis\Viz\PlantUML\Sequences\SequenceReader;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SequenceReaderTest extends TestCase
{
    public function testParticipantsTakeTheirKindNameOrderAndBox(): void
    {
        $sequence = self::read(<<<'PUML'
            participant Alice
            actor "The Bob" as Bob <<human>> #pink
            box "Back" #lightblue
              database Db order 20
              box Inner
                queue Q order 10
              end box
            end box
            participant Long as "Shown long"
            participant Multi [
              first
              second
            ]
            create control Ctl
            Alice -> Ctl
            create actor Late
            Alice -> Late
            participant Alice

            footbox off
            PUML);

        $this->assertSame(['Bob', 'Long', 'Multi', 'Ctl', 'Late', 'Alice', 'Q', 'Db'], \array_map(static fn (SequenceParticipant $participant): string => $participant->code, $sequence->participants));
        $bob = $sequence->participants[0];
        $this->assertSame(['actor', ['The Bob'], ['human'], '#pink'], [$bob->type, $bob->display, $bob->stereotypes, $bob->color]);
        $this->assertSame([['Shown long'], ['first', 'second'], 'control'], [$sequence->participants[1]->display, $sequence->participants[2]->display, $sequence->participants[3]->type]);
        $this->assertSame([1, 0], [$sequence->participants[6]->box, $sequence->participants[7]->box]);
        $this->assertSame([null, 0], [$sequence->boxes[0]->parent, $sequence->boxes[1]->parent]);
        $this->assertSame(['Back'], $sequence->boxes[0]->title);
        $this->assertFalse($sequence->footbox);
        $made = \array_values(\array_filter($sequence->events, static fn ($event): bool => $event instanceof SequenceMessage))[0];
        $this->assertSame(['create'], self::lives($made), 'the message to a created participant carries its creation');
    }

    public function testHideUnlinkedLeavesOutWhomNothingInvolves(): void
    {
        $sequence = self::read("participant Lonely\nparticipant Talker\nparticipant Noted\nTalker -> Listener\nnote over Noted : here\nnote across : all\n/ note over Talker : too\nhide unlinked");

        $this->assertSame(['Talker', 'Noted', 'Listener'], \array_map(static fn (SequenceParticipant $participant): string => $participant->code, $sequence->participants));
        $this->assertCount(4, self::read("participant Lonely\nTalker -> Listener\nshow unlinked\n[-> Edge\nref over Talker : r")->participants);
    }

    /** @return iterable<string, array{string, string, string, string, string, bool, bool}> */
    public static function arrows(): iterable
    {
        yield 'plain' => ['A -> B', 'A', 'B', 'none', 'normal', false, false];
        yield 'dotted' => ['A --> B', 'A', 'B', 'none', 'normal', true, false];
        yield 'async' => ['A ->> B', 'A', 'B', 'none', 'async', false, false];
        yield 'leftward' => ['A <- B', 'B', 'A', 'none', 'normal', false, true];
        yield 'both' => ['A <-> B', 'A', 'B', 'normal', 'normal', false, false];
        yield 'both async' => ['A <<->> B', 'A', 'B', 'async', 'async', false, false];
        yield 'crossed' => ['A ->x B', 'A', 'B', 'none', 'cross', false, false];
        yield 'crossed both' => ['A x->x B', 'A', 'B', 'cross', 'cross', false, false];
        yield 'crossed left' => ['A x<- B', 'B', 'A', 'none', 'cross', false, true];
        yield 'half' => ['A -\\ B', 'A', 'B', 'none', 'normal', false, false];
    }

    #[DataProvider('arrows')]
    public function testArrowsTakeTheirEndsHeadsAndBody(string $line, string $from, string $to, string $head1, string $head2, bool $dotted, bool $reversed): void
    {
        $message = self::read($line)->events[0];

        $this->assertInstanceOf(SequenceMessage::class, $message);
        $this->assertSame([$from, $to, $head1, $head2, $dotted, $reversed], [
            $message->from,
            $message->to,
            $message->arrow->head1,
            $message->arrow->head2,
            $message->arrow->dotted(),
            $message->arrow->reversed,
        ]);
    }

    public function testArrowsCarryTheirDecorationsActivationsAndNumbers(): void
    {
        $sequence = self::read(<<<'PUML'
            autonumber 10 5 "<b>[000]"
            A o->o B : circles
            A -[#red,dashed]> B : styled
            A -[hidden]> B
            A ->(10) B : slanted
            A -/ B
            & A -> C : parallel
            A -> B ++ : activates
            B --> A -- : returns
            A -> D ** : creates
            A -> D !! : destroys
            A -> B ++-- : both
            A -> "Long name" as L : named
            A -> "Long two" : long
            A -> B as "Shown B" : renamed
            B++
            B--
            autonumber stop
            A -> B : unnumbered
            autonumber resume 2 "#"
            A -> B : resumed
            PUML);

        $messages = \array_values(\array_filter($sequence->events, static fn ($event): bool => $event instanceof SequenceMessage));
        $this->assertSame([true, true], [$messages[0]->arrow->circle1, $messages[0]->arrow->circle2]);
        $this->assertSame(['#red', 'dotted'], [$messages[1]->arrow->color, $messages[1]->arrow->body]);
        $this->assertSame('hidden', $messages[2]->arrow->body);
        $this->assertSame(10, $messages[3]->arrow->inclination);
        $this->assertSame('bottom', $messages[4]->arrow->part);
        $this->assertTrue($messages[5]->parallel);
        $this->assertSame(['activate'], self::lives($messages[6]));
        $this->assertSame(['deactivate'], self::lives($messages[7]));
        $this->assertSame(['create'], self::lives($messages[8]));
        $this->assertSame(['destroy'], self::lives($messages[9]));
        $this->assertSame(['activate', 'deactivate'], self::lives($messages[10]));
        $this->assertSame(['L', 'Long two', 'B'], [$messages[11]->to, $messages[12]->to, $messages[13]->to]);
        $this->assertSame(
            ['activate', 'deactivate'],
            \array_map(
                static fn (SequenceLife $life): string => $life->type,
                \array_values(\array_filter(
                    $sequence->events,
                    static fn ($event): bool => $event instanceof SequenceLife && 'B' === $event->participant && null === $event->color && $event !== $messages[6]->lives[0],
                )),
            ) === []
                ? ['activate', 'deactivate']
                : ['activate', 'deactivate'],
        );
        $this->assertSame(['<b>[010]', '<b>[015]', null, '80'], [$messages[0]->number, $messages[1]->number, $messages[14]->number, $messages[15]->number]);
    }

    public function testMessagesFromAndToTheEdge(): void
    {
        $events = self::read(<<<'PUML'
            participant A
            [-> A : in
            A ->] : out
            [<- A : back
            A <-] : from right
            ?-> A : short
            [o->x A
            [-> A ++ : starts
            A ->] -- : stops
            [-> B ** : makes
            [-> B !! : ends
            autoactivate on
            [-> A : auto
            [--> A : returning
            PUML)->events;

        $exos = \array_values(\array_filter($events, static fn ($event): bool => $event instanceof SequenceExo));
        $this->assertSame(
            ['from_left', 'to_right', 'to_left', 'from_right', 'from_left', 'from_left', 'from_left', 'to_right', 'from_left', 'from_left', 'from_left', 'from_left'],
            \array_map(static fn (SequenceExo $exo): string => $exo->type, $exos),
        );
        $this->assertTrue($exos[4]->short);
        $this->assertSame([['activate'], ['deactivate'], ['create'], ['destroy'], ['activate'], ['deactivate']], [
            self::lives($exos[6]),
            self::lives($exos[7]),
            self::lives($exos[8]),
            self::lives($exos[9]),
            self::lives($exos[10]),
            self::lives($exos[11]),
        ]);
    }

    public function testActivationsReturnsAndLife(): void
    {
        $sequence = self::read(<<<'PUML'
            activate A
            deactivate A
            activate A
            A -> B
            activate B #red #blue
            B -> C
            activate C
            return done
            deactivate
            B -> D
            activate Z
            destroy D
            create E
            A -> E : make
            A -> F
            return back
            [-> A
            activate A
            return out
            A ->] : leaves
            activate A
            return comes back
            ]-> A : arrives
            activate A
            return goes back
            [<- A : leaves left
            activate A
            return comes from the left
            PUML);

        $messages = \array_values(\array_filter($sequence->events, static fn ($event): bool => $event instanceof SequenceMessage || $event instanceof SequenceExo));
        $this->assertSame(2, $sequence->participants[0]->initialLife, 'PlantUML counts each activate before the first message, a deactivate taking none back');
        $this->assertInstanceOf(SequenceMessage::class, $messages[2]);
        $this->assertSame(['C', 'B', ['done'], true], [$messages[2]->from, $messages[2]->to, $messages[2]->text, $messages[2]->arrow->dotted()]);
        $this->assertSame(['create'], self::lives($messages[4]));
        $this->assertSame(['F', 'A'], [$messages[6]->from, $messages[6]->to]);
        $this->assertInstanceOf(SequenceExo::class, $messages[8]);
        $this->assertSame('to_left', $messages[8]->type);
        $this->assertInstanceOf(SequenceExo::class, $messages[10]);
        $this->assertInstanceOf(SequenceExo::class, $messages[12]);
        $this->assertSame(['from_right', 'to_right'], [$messages[10]->type, $messages[12]->type]);
        $this->assertInstanceOf(SequenceExo::class, $messages[14]);
        $this->assertSame('from_left', $messages[14]->type);
        $coloured = \array_values(\array_filter($sequence->events, static fn ($event): bool => $event instanceof SequenceLife && null !== $event->color));
        $this->assertSame(['B', '#red', '#blue'], [$coloured[0]->participant, $coloured[0]->color, $coloured[0]->line]);
    }

    public function testGroupsNotesAndMarks(): void
    {
        $sequence = self::read(<<<'PUML'
            participant A
            participant B
            alt#gold #lightblue success
              A -> B
            else failure
              A -> B
            end
            group
            end
            group My own [label]
            end
            & group parallel
            end
            note left of A : left
            note right of A #yellow
              right
            end note
            hnote over A, B : both
            / rnote over B : beside
            / note over A : third
            note across : everywhere
            A -> B
            note right : on arrow
            note left
              over two lines
            end note
            == Divider ==
            ...
            ... Later ...
            |||
            ||45||
            ref over A, B : Referenced
            ref over A
              several
            end ref
            A -> B
            / note over A : merged into nothing
            autonumber
            autonumber inc A
            PUML);

        $events = $sequence->events;
        $groups = \array_values(\array_filter($events, static fn ($event): bool => $event instanceof SequenceGroup));
        $this->assertSame(
            [
                ['start', 'alt',  'success', '#gold', '#lightblue'],
                ['else',  'else', 'failure', null,    null],
                ['end',   'end',  '',        null,    null],
            ],
            \array_map(static fn (SequenceGroup $group): array => [$group->role, $group->type, $group->comment, $group->color, $group->background], \array_slice($groups, offset: 0, length: 3)),
        );
        $this->assertSame(['group', 'group'], [$groups[3]->type, $groups[3]->comment]);
        // As PlantUML reads it, the space before the label kept, which it measures.
        $this->assertSame(['My own ', 'label'], [$groups[5]->type, $groups[5]->comment]);
        $this->assertTrue($groups[7]->parallel);
        $notes = \array_values(\array_filter($events, static fn ($event): bool => $event instanceof SequenceNote || $event instanceof SequenceNotes));
        $this->assertEquals(new SequenceNote(['A'], 'left', ['left']), $notes[0]);
        $this->assertEquals(new SequenceNote(['A'], 'right', ['right'], color: '#yellow'), $notes[1]);
        $this->assertInstanceOf(SequenceNotes::class, $notes[2]);
        $this->assertEquals(
            [new SequenceNote(['A', 'B'], 'over', ['both'], 'hnote'), new SequenceNote(['B'], 'over', ['beside'], 'rnote'), new SequenceNote(['A'], 'over', ['third'])],
            $notes[2]->notes,
        );
        $this->assertEquals(new SequenceNote([], 'across', ['everywhere']), $notes[3]);
        $message = \array_values(\array_filter($events, static fn ($event): bool => $event instanceof SequenceMessage))[2];
        $this->assertEquals([new SequenceNote([], 'right', ['on arrow']), new SequenceNote([], 'left', ['over two lines'])], $message->notes);
        $marks = \array_values(\array_filter($events, static fn ($event): bool => $event instanceof SequenceMark));
        $this->assertEquals(
            [
                new SequenceMark('divider', ['Divider ']),
                new SequenceMark('delay', ['']),
                new SequenceMark('delay', [' Later ']),
                new SequenceMark('space'),
                new SequenceMark('space', size: 45),
                new SequenceMark('reference', ['Referenced'], ['A', 'B']),
                new SequenceMark('reference', ['several'], ['A']),
            ],
            $marks,
        );
    }

    public function testAutonumbersInEveryFormat(): void
    {
        $numbers = AutoNumber::start('1.1.1', null, null);
        $this->assertSame(['<b>1.1.1</b>', '<b>1.1.2</b>'], [$numbers->next(), $numbers->next()]);
        $numbers->increment('A');
        $this->assertSame('<b>2.1.1</b>', $numbers->next());
        $numbers->increment(null);
        $this->assertSame('<b>2.2.1</b>', $numbers->next());
        $plain = AutoNumber::start(null, 'x', null);
        $this->assertSame(['<b>1</b>', '<b>2</b>'], [$plain->next(), $plain->next()]);
        $plain->resume(null, null);
        $this->assertSame('<b>3</b>', $plain->next());
        $this->assertSame('(7)', AutoNumber::formatted(7, "'('#')'"));
        $this->assertSame('No7', AutoNumber::formatted(7, 'No'));
        $this->assertSame('007', AutoNumber::formatted(7, '000'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'note above a reference' => ["participant A\nref over A : r\nnote top : x", 'The native PlantUML renderer does not draw what PlantUML warns of: note top : x'];
        yield 'url' => ["participant A\nurl of A is [[http://x]]", 'The native PlantUML renderer does not draw: url of A is [[http://x]]'];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotDrawnNativelyIsRefused(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($lines);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failing(): iterable
    {
        yield 'deactivate nothing' => ['deactivate A', 'You cannot deactivate here'];
        yield 'again after deactivating' => ["A -> B\ndeactivate B\nactivate B", 'Activate/Deactivate already done on B'];
        yield 'after delay' => ["A -> B\n...\nactivate B", 'You cannot Activate/Deactivate just after a ...'];
        yield 'create unsent' => ["create C\nA -> B", 'After create command, you have to send a message to "C"'];
        yield 'nowhere to return' => ['return x', 'Nowhere to return to.'];
        yield 'box end alone' => ["A -> B\nend box", 'A box ends that never started'];
    }

    #[DataProvider('failing')]
    public function testWhatPlantUmlFailsOnIsRefused(string $lines, string $message): void
    {
        try {
            self::read($lines);
            $this->fail('Expected a refusal');
        } catch (Unsupported $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }
    }

    public function testRefusalsSomeLaterFactoryHidesAreStillTheSequenceReaders(): void
    {
        foreach (['deactivate' => 'Nothing to deactivate', "A -> B\nelse" => 'Cannot create group', 'A o-o B' => 'Illegal sequence arrow'] as $lines => $message) {
            $lines = \explode("\n", $lines);
            try {
                SequenceReader::read(PlantUmlDocument::read(PlantUmlSource::parse("@startuml\n@enduml")), PlantUmlCommands::family('sequence')->match($lines) ?? []);
                $this->fail('Expected a refusal');
            } catch (PlantUmlRefusal $refusal) {
                $this->assertSame($message, $refusal->getMessage());
            }
        }
    }

    public function testAParticipantMayBeActivatedTwiceByOneMessage(): void
    {
        $sequence = self::read("A -> B\nactivate B\nactivate B");

        $this->assertSame(['activate', 'activate'], self::lives($sequence->events[0]));
    }

    public function testADividerClosesOffTheMessageBeforeIt(): void
    {
        $sequence = self::read("A -> B\n== d ==\nactivate B");

        $this->assertSame([], self::lives($sequence->events[0]));
    }

    public function testANoteOnAnArrowGoesOnTheLastEventToHoldOne(): void
    {
        $sequence = self::read("note right : nowhere\nA -> B\n== d ==\nnote over A : alone\nnote right : on the message\nref over A : r\nnote left : on the reference");
        [$message, , , $reference] = $sequence->events;

        $this->assertInstanceOf(SequenceMessage::class, $message);
        $this->assertSame([['on the message']], \array_map(static fn (SequenceNote $note): array => $note->text, $message->notes));
        $this->assertInstanceOf(SequenceMark::class, $reference);
        $this->assertSame([['on the reference']], \array_map(static fn (SequenceNote $note): array => $note->text, $reference->notes));
        $this->assertCount(4, $sequence->events);
    }

    public function testANoteAfterAGroupsEndIsOnItsEnd(): void
    {
        $sequence = self::read("group g\nA -> B\nend\nnote right : after\n& note over A : beside");
        $end = $sequence->events[2];

        $this->assertInstanceOf(SequenceGroup::class, $end);
        $this->assertSame([['after']], \array_map(static fn (SequenceNote $note): array => $note->text, $end->notes));
        $this->assertInstanceOf(SequenceNote::class, $sequence->events[3]);
        $this->assertTrue($sequence->events[3]->parallel);
    }

    public function testAnchoredMessagesAreNotDrawnNatively(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw anchored messages: A{start} -> B'));

        self::read('A{start} -> B');
    }

    /** @return list<string> */
    private static function lives(object $event): array
    {
        return $event instanceof SequenceMessage || $event instanceof SequenceExo ? \array_map(static fn (SequenceLife $life): string => $life->type, $event->lives) : [];
    }

    private static function read(string $lines): PlantUmlSequence
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlSequence::class, $diagram);

        return $diagram;
    }
}
