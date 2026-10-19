<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Descriptions;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Descriptions\PlantUmlDescriptions;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionReaderTest extends TestCase
{
    public function testElementsTakeTheirSymbolsFromKeywordsAndShortForms(): void
    {
        $diagram = self::read(<<<'PUML'
            component Comp
            [Short comp] as SC
            node "Long node" as N <<server>> #pink
            database DB
            () "Iface"
            ()Port
            (Use it) as U
            (Busy)/ as BU
            :Someone: as A
            :Boss:/ as B
            actor/ Manager
            usecase/ Work
            rectangle R #back:pink;line:red
            left to right direction
            PUML);

        $symbols = \array_map(static fn ($element): string => $element->symbol, $diagram->elements);
        $this->assertSame(
            [
                'Comp' => 'component',
                'SC' => 'component',
                'N' => 'node',
                'DB' => 'database',
                'Iface' => 'interface',
                'Port' => 'interface',
                'U' => 'usecase',
                'BU' => 'usecase',
                'A' => 'actor',
                'B' => 'actor',
                'Manager' => 'actor',
                'Work' => 'usecase',
                'R' => 'rectangle',
            ],
            $symbols,
        );
        $this->assertSame([['Long node'], ['server'], '#pink'], [$diagram->elements['N']->display, $diagram->elements['N']->stereotypes, $diagram->elements['N']->color]);
        $this->assertSame([['Short comp'], ['Use it'], ['Someone']], [$diagram->elements['SC']->display, $diagram->elements['U']->display, $diagram->elements['A']->display]);
        $this->assertSame([false, true, true, true, true], [
            $diagram->elements['U']->business,
            $diagram->elements['BU']->business,
            $diagram->elements['B']->business,
            $diagram->elements['Manager']->business,
            $diagram->elements['Work']->business,
        ]);
        $this->assertSame(Direction::LeftRight, $diagram->direction);
    }

    public function testMultilineElementsAndContainers(): void
    {
        $diagram = self::read(<<<'PUML'
            rectangle Text [
              first line
              second line
            ]
            component Quote <<s>> #yellow as "first
              quoted"
            package "Pack age" as P <<p>> #lightblue {
              node Inner
              together {
                [Close]
              }
              frame F {
                card C
              }
            }
            cloud Sky {
            }
            PUML);

        $this->assertSame(['first line', 'second line'], $diagram->elements['Text']->display);
        $this->assertSame([['first', 'quoted'], ['s'], '#yellow'], [$diagram->elements['Quote']->display, $diagram->elements['Quote']->stereotypes, $diagram->elements['Quote']->color]);
        $this->assertSame(['Pack age', 'package', null, ['p'], '#lightblue'], [
            $diagram->groups['P']->display,
            $diagram->groups['P']->symbol,
            $diagram->groups['P']->parent,
            $diagram->groups['P']->stereotypes,
            $diagram->groups['P']->color,
        ]);
        $this->assertSame(['together', 'P'], [$diagram->groups['together#1']->symbol, $diagram->groups['together#1']->parent]);
        $this->assertSame(['frame', 'P'], [$diagram->groups['F']->symbol, $diagram->groups['F']->parent]);
        $this->assertSame(['P', 'together#1', 'F'], [$diagram->elements['Inner']->group, $diagram->elements['Close']->group, $diagram->elements['C']->group]);
        $this->assertSame('cloud', $diagram->groups['Sky']->symbol);
    }

    public function testLinksTakeTheirEndsMarksAndLabels(): void
    {
        $diagram = self::read(<<<'PUML'
            actor User
            User --> (Log in) : starts >
            (Log in) .> (Check) : <<include>>
            [Web] -up-> () API
            :Admin: --|> User
            Ghost -> User
            Other ..> Plain <<extend>>
            note "about" as N
            N .. User
            PUML);

        $links = \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to, $link->length, $link->toDecor], $diagram->links);
        $this->assertSame(
            [
                ['User',   'Log in', 2, LinkDecor::Arrow],
                ['Log in', 'Check',  1, LinkDecor::Arrow],
                ['API',    'Web',    2, LinkDecor::None],
                ['Admin',  'User',   2, LinkDecor::Extends],
                ['Ghost',  'User',   1, LinkDecor::Arrow],
                ['Other',  'Plain',  2, LinkDecor::Arrow],
                ['N',      'User',   2, LinkDecor::None],
            ],
            $links,
        );
        $this->assertSame(['starts', '>'], [$diagram->links[0]->label, $diagram->links[0]->labelArrow]);
        $this->assertSame(['<<include>>', 'extend'], [$diagram->links[1]->label, $diagram->links[5]->stereotype]);
        $this->assertSame(['usecase', 'usecase', 'component', 'interface', 'actor', 'actor', 'actor', 'actor'], [
            $diagram->elements['Log in']->symbol,
            $diagram->elements['Check']->symbol,
            $diagram->elements['Web']->symbol,
            $diagram->elements['API']->symbol,
            $diagram->elements['Admin']->symbol,
            $diagram->elements['Ghost']->symbol,
            $diagram->elements['Other']->symbol,
            $diagram->elements['Plain']->symbol,
        ]);
        $this->assertArrayNotHasKey('N', $diagram->elements, 'a note is linked as itself');
        $components = self::read("[A] --> B\nB --> C");
        $this->assertSame(
            ['component', 'interface', 'interface'],
            [$components->elements['A']->symbol, $components->elements['B']->symbol, $components->elements['C']->symbol],
            'names alone are interfaces without actors or use cases',
        );
    }

    public function testNotes(): void
    {
        $diagram = self::read(<<<'PUML'
            node N
            note left of N : beside
            note right : last
            note as M
              floating
            end note
            package P {
              node Q
              note bottom of Q
                under
              end note
            }
            N --> Q
            note on link : linked
            PUML);

        $this->assertEquals(
            [
                new PlantUmlNote('note#0', ['beside'], 'N', 'left'),
                new PlantUmlNote('note#1', ['last'], 'N', 'right'),
                new PlantUmlNote('M', ['  floating']),
                new PlantUmlNote('note#3', ['    under'], 'Q', 'bottom', group: 'P'),
                new PlantUmlNote('note#4', ['linked'], position: 'bottom', link: 0),
            ],
            $diagram->notes,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'lone name' => ["node N\nfoo", 'The native PlantUML renderer does not draw a lone name: foo'];
        yield 'port' => ["node N\nport P", 'The native PlantUML renderer does not draw port elements'];
        yield 'multiline other' => ["node N\nportin P [\nx\n]", 'The native PlantUML renderer does not draw portin elements'];
        yield 'middle circle' => ["node N\nN --0-- M", 'The native PlantUML renderer does not draw: N --0-- M'];
        yield 'container link' => ["node A\nrectangle R {\n}\nA --> R", 'The native PlantUML renderer does not draw links to containers such as R'];
        yield 'namespaced' => ['node a.b', 'The native PlantUML renderer does not draw namespaced names such as a.b'];
        yield 'archimate' => ["node N\narchimate #Business \"Thing\" as T", 'The native PlantUML renderer does not draw: archimate #Business "Thing" as T'];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotDrawnNativelyIsRefused(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($lines);
    }

    public function testWhatPlantUmlFailsOnIsRefused(): void
    {
        foreach ([
            "node A\ndatabase A" => 'This element (A) is already defined',
            "note left : x\nnode A" => 'The PlantUML note has no element to stand beside: note left : x',
            "node A\nnote on link : x" => 'The PlantUML note has no link to stand on: note on link : x',
        ] as $lines => $message) {
            try {
                self::read($lines);
                $this->fail('Expected a refusal');
            } catch (Unsupported $refusal) {
                $this->assertStringContainsString($message, $refusal->getMessage());
            }
        }
    }

    private static function read(string $lines): PlantUmlDescriptions
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlDescriptions::class, $diagram);

        return $diagram;
    }
}
