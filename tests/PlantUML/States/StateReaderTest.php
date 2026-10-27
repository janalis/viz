<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\States;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\States\PlantUmlStates;
use Janalis\Viz\PlantUML\States\StateReader;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateReaderTest extends TestCase
{
    public function testStatesTakeTheirNamesKindsAndDescriptions(): void
    {
        $states = self::read(<<<'PUML'
            state Idle
            state "Long name" as Long
            state Short as "Shown"
            state "Quoted"
            state Pick <<choice>>
            state Split <<fork>> <<custom>>
            state Merge <<join>> #pink
            state Done : finished
            Done : really\nand truly
            "Quoted" : once more
            left to right direction
            hide empty description
            PUML);

        $this->assertSame([['Idle'], ['Long name'], ['Shown'], ['Quoted']], [
            $states->nodes['Idle']->display,
            $states->nodes['Long']->display,
            $states->nodes['Short']->display,
            $states->nodes['Quoted']->display,
        ]);
        $this->assertSame(['choice', 'fork', 'join'], [$states->nodes['Pick']->kind, $states->nodes['Split']->kind, $states->nodes['Merge']->kind]);
        $this->assertSame([['custom'], '#pink'], [$states->nodes['Split']->stereotypes, $states->nodes['Merge']->color]);
        $this->assertSame(['finished', 'really', 'and truly'], $states->nodes['Done']->description);
        $this->assertSame(['once more'], $states->nodes['Quoted']->description);
        $this->assertSame([Direction::LeftRight, true], [$states->direction, $states->hideEmptyDescription]);
    }

    public function testCompositeStatesHoldRegionsEachWithItsOwnStartAndEnd(): void
    {
        $states = self::read(<<<'PUML'
            [*] --> Active
            state Active {
              [*] --> A
              A --> [*]
              --
              [*] --> B
              ||
              state Deep {
                [*] --> C
              }
            }
            end state
            Active --> [*]

            show empty description
            PUML);

        $this->assertSame([null, 0], [$states->nodes['Active']->group, $states->nodes['Active']->region]);
        $this->assertSame([['Active', 0], ['Active', 1], ['Active', 2], ['Deep', 0]], [
            [$states->nodes['A']->group, $states->nodes['A']->region],
            [$states->nodes['B']->group, $states->nodes['B']->region],
            [$states->nodes['Deep']->group, $states->nodes['Deep']->region],
            [$states->nodes['C']->group, $states->nodes['C']->region],
        ]);
        $this->assertSame(['--', '||'], $states->composites['Active']);
        $this->assertSame([], $states->composites['Deep']);
        $this->assertSame(
            [
                ['*start*',         'Active'],
                ['*start*Active',   'A'],
                ['A',               '*end*Active'],
                ['*start*Active#1', 'B'],
                ['*start*Deep',     'C'],
                ['Active',          '*end*'],
            ],
            \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to], $states->links),
        );
        $this->assertSame(['start', 'end'], [$states->nodes['*start*']->kind, $states->nodes['*end*']->kind]);
        $this->assertSame([], $states->nodes['*start*']->display);
        $this->assertFalse($states->hideEmptyDescription);
    }

    public function testTransitionsTakeTheirArrowsAndEnds(): void
    {
        $links = self::read(<<<'PUML'
            state A
            A --> B : go
            A -> C
            A ---> D
            A -up-> E : back
            A -left-> F
            A -right-> G
            A x--> H
            A -->o I
            A -[#red,dashed]-> J
            K <-- A
            A --> A[H]
            A --> [H*]
            A --> ==bar==
            PUML)->links;

        $this->assertSame(['A', 'B', 2, 'go', LinkDecor::None, LinkDecor::Arrow], [$links[0]->from, $links[0]->to, $links[0]->length, $links[0]->label, $links[0]->fromDecor, $links[0]->toDecor]);
        $this->assertSame([1, 3], [$links[1]->length, $links[2]->length]);
        $this->assertSame(['E', 'A', LinkDecor::Arrow, 'back'], [$links[3]->from, $links[3]->to, $links[3]->fromDecor, $links[3]->label]);
        $this->assertSame(['F', 'A', 1], [$links[4]->from, $links[4]->to, $links[4]->length]);
        $this->assertSame(['A', 'G', 1], [$links[5]->from, $links[5]->to, $links[5]->length]);
        $this->assertSame([LinkDecor::NotNavigable, LinkDecor::Circle], [$links[6]->fromDecor, $links[7]->toDecor]);
        $this->assertSame(['#red', true], [$links[8]->color, $links[8]->dashed]);
        $this->assertSame(['A', 'K'], [$links[9]->from, $links[9]->to]);
        $this->assertSame(['*h*A', '*h**', '==bar'], [$links[10]->to, $links[11]->to, $links[12]->to]);
    }

    public function testHistoryAndBarsAreTheirOwnKinds(): void
    {
        $states = self::read("state A {\n  A2 --> [H]\n  A2 --> [H*]\n}\nB --> A[H*]\nB --> ==sync==");

        $this->assertSame(['history', 'deephistory', 'deephistory', 'fork'], [
            $states->nodes['*h*A']->kind,
            $states->nodes['*h**A']->kind,
            $states->nodes['*h**A']->kind,
            $states->nodes['==sync']->kind,
        ]);
    }

    public function testNotesStandBesideFloatOrSitOnTransitions(): void
    {
        $states = self::read(<<<'PUML'
            state A
            note left of A : beside
            note right : the last
            note "floating" as N
            note as M
              several
            end note
            state B {
              state C
              note top of C
                inside
              end note
            }
            A --> B
            note on link : on it
            note left on link
              link note
            end note
            PUML);

        $this->assertEquals(
            [
                new PlantUmlNote('note#0', ['beside'], 'A', 'left'),
                new PlantUmlNote('note#1', ['the last'], 'A', 'right'),
                new PlantUmlNote('N', ['floating']),
                new PlantUmlNote('M', ['several']),
                new PlantUmlNote('note#4', ['inside'], 'C', 'top', group: 'B'),
                new PlantUmlNote('note#5', ['on it'], position: 'bottom', link: 0),
                new PlantUmlNote('note#6', ['link note'], position: 'left', link: 0),
            ],
            $states->notes,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'region outside' => ["state A\n--", 'A PlantUML region separator stands outside any composite state'];
        yield 'namespaced' => ['state a.b', 'The native PlantUML renderer does not draw namespaced names such as a.b'];
        yield 'a state of a later region named outside it' => ["state A {\n  [*] --> B\n  --\n  [*] --> C\n}\nX --> C", 'The state C cannot be used here.'];
        yield 'a state named outside from within a later region' => ["state X\nstate A {\n  [*] --> B\n  --\n  C --> X\n}", 'The state X cannot be used here.'];
        yield 'url' => ["state A\nurl of A is [[http://x]]", 'The native PlantUML renderer does not draw: url of A is [[http://x]]'];
        yield 'a composite removed' => ["state A {\n  state B\n}\nremove A", 'The native PlantUML renderer does not hide or remove a composite state or a state inside one: A'];
        yield 'a state in a composite hidden' => ["state A {\n  state B\n}\nhide B", 'The native PlantUML renderer does not hide or remove a composite state or a state inside one: B'];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotDrawnNativelyIsRefused(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($lines);
    }

    public function testNotesWithNothingToStandOnAreRefusedAsPlantUmlRefusesThem(): void
    {
        foreach ([
            "note left : alone\nstate A {\n}" => 'The PlantUML note has no state to stand beside: note left : alone',
            "state A {\n}\nnote on link : alone" => 'The PlantUML note has no link to stand on: note on link : alone',
        ] as $lines => $message) {
            try {
                self::read($lines);
                $this->fail('Expected a refusal');
            } catch (Unsupported $refusal) {
                $this->assertSame($message, $refusal->getMessage());
                $this->assertInstanceOf(PlantUmlRefusal::class, $refusal->getPrevious());
            }
        }
        $this->expectException(PlantUmlRefusal::class);
        StateReader::read(PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nnote left : alone\n@enduml")), PlantUmlCommands::family('state')->match(['note left : alone']) ?? []);
    }

    private static function read(string $lines): PlantUmlStates
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlStates::class, $diagram);

        return $diagram;
    }
}
