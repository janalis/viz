<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\Source\MermaidSequenceReader;
use Janalis\Viz\Mermaid\Source\MermaidSequenceWriter;
use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\NotePlacement;
use Janalis\Viz\Model\Sequence\ParticipantKind;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\Sequence\SequenceDiagram as Sequence;
use Janalis\Viz\Tests\Support\SequenceSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidSequenceTest extends TestCase
{
    public function testASequenceDiagramIsReadIntoTheSharedModel(): void
    {
        $diagram = new MermaidReader()->read(<<<'MMD'
            sequenceDiagram
              title Login
              box Aqua Front
                actor U as User
                participant W@{ "type" : "boundary" }
              end
              U->>+W: hello
              W-->>-U: hi
              alt ok
                W-)U: async
              else not ok
                W--xU: lost
                W->U: plain
              end
              par one
                U<<->>W: both
              and two
                U->W: two
              end
              critical c
                U->W: c
              option o
                U->W: o
              end
              par_over p
                U->W: p
              end
              loop l
                create participant N
                U->>N: make
                destroy N
                U-|\N: half
              end
              rect rgb(0, 0, 255)
                Note over U,W: over
              end
              opt maybe
                Note left of U: left
              end
              break stop
                U->W: b
              end
            MMD);

        $this->assertInstanceOf(SequenceDiagram::class, $diagram);
        $this->assertSame('Login', $diagram->title);
        $this->assertSame(
            [
                ['U', 'User', ParticipantKind::Actor],
                ['W', null,   ParticipantKind::Boundary],
            ],
            \array_map(static fn ($participant): array => [$participant->id, $participant->label, $participant->kind], \array_slice($diagram->participants, offset: 0, length: 2)),
        );
        $this->assertSame(['Aqua Front', ['U', 'W']], [$diagram->boxes[0]->label, $diagram->boxes[0]->participants]);
        $this->assertEquals(
            [new Message('U', 'W', 'hello'), new Activation('W'), new Message('W', 'U', 'hi', dashed: true), new Activation('W', active: false)],
            \array_slice($diagram->steps, offset: 0, length: 4),
        );
        $alt = $diagram->steps[4];
        $this->assertInstanceOf(Fragment::class, $alt);
        $this->assertSame([FragmentKind::Alt, ['ok', 'not ok']], [$alt->kind, \array_map(static fn ($section): string => $section->label, $alt->sections)]);
        $this->assertEquals([new Message('W', 'U', 'async', MessageHead::Open)], $alt->sections[0]->steps);
        $this->assertEquals([new Message('W', 'U', 'lost', MessageHead::Cross, dashed: true), new Message('W', 'U', 'plain', MessageHead::None)], $alt->sections[1]->steps);
        $kinds = \array_map(static fn ($step): ?FragmentKind => $step instanceof Fragment ? $step->kind : null, \array_slice($diagram->steps, offset: 4));
        $this->assertSame(
            [FragmentKind::Alt, FragmentKind::Par, FragmentKind::Critical, FragmentKind::Par, FragmentKind::Loop, FragmentKind::Highlight, FragmentKind::Opt, FragmentKind::Break],
            $kinds,
        );
        $par = $diagram->steps[5];
        $this->assertInstanceOf(Fragment::class, $par);
        $this->assertEquals(new Message('U', 'W', 'both', both: true), $par->sections[0]->steps[0]);
        $loop = $diagram->steps[8];
        $this->assertInstanceOf(Fragment::class, $loop);
        $this->assertEquals([new Lifecycle('N'), new Message('U', 'N', 'make'), new Lifecycle('N', create: false), new Message('U', 'N', 'half')], $loop->sections[0]->steps);
        $rect = $diagram->steps[9];
        $this->assertInstanceOf(Fragment::class, $rect);
        $this->assertSame('rgb(0, 0, 255)', $rect->sections[0]->label);
        $this->assertEquals([new Note('over', NotePlacement::Over, ['U', 'W'])], $rect->sections[0]->steps);
    }

    public function testTheSharedModelIsWrittenAsASequenceDiagram(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Login"
            ---
            sequenceDiagram
                autonumber
                box LightBlue Back end
                    participant Db@{ "type" : "database" }
                    participant Queue@{ "type" : "queue" }
                end
                actor User
                participant Web@{ "type" : "boundary" } as Web "app"
                participant p0 as end
                User->>Web: log in#59; #35;1<br>please
                activate Web
                alt known
                    Web-)Db: lookup
                    Db-->Web: row
                else unknown
                    Web--xUser: refuse
                end
                par fan out
                    Web<<->>Queue: job
                and and log
                end
                rect rgba(0, 0, 0, 0.1)
                    note over Web,Db: busy
                end
                opt maintenance
                    note left of p0: left
                end
                loop every minute
                    create participant Worker@{ "type" : "control" }
                    Queue->>Worker: spawn
                end
                critical commit
                option on failure
                    Db<<->>Db: rollback
                end
                opt
                    Web<<->>Queue: either
                end
                break stop
                    destroy Worker
                    Queue-xWorker: kill
                end
                note over User,Worker: Later
                note over User,Worker: … a while
                note over User,Worker: … 
                deactivate Web
                note right of User: done

            MMD, MermaidSequenceWriter::write(SequenceSample::diagram()));
    }

    public function testASeparatorNeedsAParticipantToStandOverAndAStepOfNoKnownKindIsSkipped(): void
    {
        $unknown = new class implements \Janalis\Viz\Model\Sequence\Step {};

        $this->assertSame("sequenceDiagram\n", MermaidSequenceWriter::write(new Sequence(steps: [new \Janalis\Viz\Model\Sequence\Separator('x'), $unknown])));
    }

    #[DataProvider('corpus')]
    public function testWritingWhatWasReadReachesAFixedPoint(string $file): void
    {
        $first = MermaidSequenceWriter::write(MermaidSequenceReader::read((string) \file_get_contents($file)));

        $this->assertSame($first, MermaidSequenceWriter::write(MermaidSequenceReader::read($first)));
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        $files = \glob(__DIR__.'/../../fixtures/mermaid/upstream/sequence/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }
}
