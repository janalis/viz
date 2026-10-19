<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Classes;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassLink;
use Janalis\Viz\PlantUML\Classes\ClassNote;
use Janalis\Viz\PlantUML\Classes\PlantUmlClasses;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassReaderTest extends TestCase
{
    public function testClassifiersTakeTheirKindNameBodyAndDecorations(): void
    {
        $classes = self::read(<<<'PUML'
            abstract class Shape<T> <<entity>> #pink {
              - name : String
              + area() : double
            }
            -interface "Drawable thing" as Drawable
            static class Util
            enum Color
            class Circle extends Shape implements Drawable, Other
            interface Fancy extends Drawable
            Circle : radius : double
            Circle <<geometry, shape>>
            class Shape {
              -- more --
            }
            left to right direction

            hide footbox
            PUML);

        $shape = $classes->entities['Shape'];
        $this->assertSame(['class', 'T', ['entity'], '#pink'], [$shape->type, $shape->generic, $shape->stereotypes, $shape->color], 'declared again as a class, as PlantUML lets it');
        $this->assertSame(['  - name : String', '  + area() : double', '  -- more --'], $shape->body);
        $this->assertSame(['interface', ['Drawable thing'], '-'], [$classes->entities['Drawable']->type, $classes->entities['Drawable']->display, $classes->entities['Drawable']->visibility]);
        $this->assertSame('class', $classes->entities['Util']->type);
        $this->assertSame(['radius : double'], $classes->entities['Circle']->body);
        $this->assertSame(['geometry', 'shape'], $classes->entities['Circle']->stereotypes);
        $this->assertSame('interface', $classes->entities['Other']->type);
        $this->assertSame(
            [
                ['Shape',    'Circle', false],
                ['Drawable', 'Circle', true],
                ['Other',    'Circle', true],
                ['Drawable', 'Fancy',  false],
            ],
            \array_map(static fn (ClassLink $link): array => [$link->from, $link->to, $link->dashed], $classes->links),
        );
        $this->assertSame(Direction::LeftRight, $classes->direction);
    }

    /** @return iterable<string, array{string, string, string, LinkDecor, LinkDecor, int}> */
    public static function links(): iterable
    {
        yield 'extends' => ['A <|-- B', 'A', 'B', LinkDecor::Extends, LinkDecor::None, 2];
        yield 'composition across' => ['A *- B', 'A', 'B', LinkDecor::Composition, LinkDecor::None, 1];
        yield 'aggregation long' => ['A o--- B', 'A', 'B', LinkDecor::Aggregation, LinkDecor::None, 3];
        yield 'arrow' => ['A --> B', 'A', 'B', LinkDecor::None, LinkDecor::Arrow, 2];
        yield 'up turns it round' => ['A -up-> B', 'B', 'A', LinkDecor::Arrow, LinkDecor::None, 2];
        yield 'left is across' => ['A -left-|> B', 'B', 'A', LinkDecor::Extends, LinkDecor::None, 1];
        yield 'right' => ['A -r-> B', 'A', 'B', LinkDecor::None, LinkDecor::Arrow, 1];
        yield 'down letter' => ['A -d-o B', 'A', 'B', LinkDecor::None, LinkDecor::Aggregation, 2];
        yield 'crowfoot' => ['A }o--o{ B', 'A', 'B', LinkDecor::CircleCrowfoot, LinkDecor::CircleCrowfoot, 2];
        yield 'both' => ['A <--> B', 'A', 'B', LinkDecor::Arrow, LinkDecor::Arrow, 2];
    }

    #[DataProvider('links')]
    public function testLinksTakeTheirMarksLengthAndDirection(string $line, string $from, string $to, LinkDecor $fromDecor, LinkDecor $toDecor, int $length): void
    {
        $link = self::read("class A\n".$line)->links[0];

        $this->assertSame([$from, $to, $fromDecor, $toDecor, $length], [$link->from, $link->to, $link->fromDecor, $link->toDecor, $link->length]);
    }

    public function testLinkLabelsStylesAndWeights(): void
    {
        $links = self::read(<<<'PUML'
            class A
            A "1" *-- "many" B : has >
            A --> C : "0..1" uses "*"
            A --> D : "x" tail
            A --> E : head "y"
            A ..> F : < back
            A -[#red,dashed,bold,dotted,hidden,norank,thickness=3,plain]-> G : >
            A -up-> H : reads >
            @2 A --> I
            A --> J : "quoted"
            PUML)->links;

        $this->assertSame(['1', 'many', 'has', '>'], [$links[0]->firstLabel, $links[0]->secondLabel, $links[0]->label, $links[0]->labelArrow]);
        $this->assertSame(['0..1', '*', 'uses'], [$links[1]->firstLabel, $links[1]->secondLabel, $links[1]->label]);
        $this->assertSame(['x', null, 'tail'], [$links[2]->firstLabel, $links[2]->secondLabel, $links[2]->label]);
        $this->assertSame([null, 'y', 'head'], [$links[3]->firstLabel, $links[3]->secondLabel, $links[3]->label]);
        $this->assertSame(['back', '<', true], [$links[4]->label, $links[4]->labelArrow, $links[4]->dashed]);
        $styled = $links[5];
        $this->assertSame(['#red', true, true, true, true, true, 3.0, null, '>'], [
            $styled->color,
            $styled->dashed,
            $styled->bold,
            $styled->dotted,
            $styled->hidden,
            $styled->norank,
            $styled->thickness,
            $styled->label,
            $styled->labelArrow,
        ]);
        $this->assertSame(['H', 'reads', '<'], [$links[6]->from, $links[6]->label, $links[6]->labelArrow]);
        $this->assertSame(2.0, $links[7]->weight);
        $this->assertSame('quoted', $links[8]->label);
    }

    public function testGroupsNotesAndVisibility(): void
    {
        $classes = self::read(<<<'PUML'
            package "Net work" as net <<cloud>> #lightblue {
              class Server
              together {
                class A
              }
              namespace inner {
                class B
              }
            }
            package empty {}
            class Server
            note "floating" as N1
            note as N2
              several
              lines
            end note
            note left of Server : beside
            note top : on the last one
            note right of A
              over
              two
            end note
            Server --> A
            note on link : on the link
            note bottom on link
              link note
            end note
            hide empty members
            show Server methods
            hide <<cloud>> circle
            hide Server
            hide @unlinked
            PUML);

        $net = $classes->groups['net'];
        $this->assertSame(['Net work', 'package', null, '<<cloud>>', '#lightblue'], [$net->display, $net->type, $net->parent, $net->stereotype, $net->color]);
        $this->assertSame(['together', 'net'], [$classes->groups['together#1']->type, $classes->groups['together#1']->parent]);
        $this->assertSame('net', $classes->groups['inner']->parent);
        $this->assertNull($classes->groups['empty']->parent);
        $this->assertSame(['net', 'together#1', 'inner'], [$classes->entities['Server']->group, $classes->entities['A']->group, $classes->entities['B']->group]);
        $this->assertEquals(
            [
                new ClassNote('N1', ['floating']),
                new ClassNote('N2', ['  several', '  lines']),
                new ClassNote('note#2', ['beside'], 'Server', 'left'),
                new ClassNote('note#3', ['on the last one'], 'Server', 'top'),
                new ClassNote('note#4', ['  over', '  two'], 'A', 'right'),
                new ClassNote('note#5', ['on the link'], position: 'bottom', link: 0),
                new ClassNote('note#6', ['  link note'], position: 'bottom', link: 0),
            ],
            $classes->notes,
        );
        $this->assertSame(
            [
                [false, null,        true,  'member'],
                [true,  'Server',    false, 'method'],
                [false, '<<cloud>>', false, 'circle'],
                [false, 'Server',    false, 'entity'],
                [false, '@unlinked', false, 'entity'],
            ],
            $classes->visibility,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'qualifier' => ['A [key] --> B', 'The native PlantUML renderer does not draw: A [key] --> B'];
        yield 'middle circle' => ['A --0-- B', 'The native PlantUML renderer does not draw: A --0-- B'];
        yield 'object' => ['object o', 'The native PlantUML renderer does not draw: object o'];
        yield 'namespaced' => ['class a.B', 'The native PlantUML renderer does not draw namespaced names such as a.B'];
        yield 'note without entity' => ["note left : alone\nclass A", 'The PlantUML note has no entity to stand beside: note left : alone'];
        yield 'kind changed' => ["entity E\nclass E", 'PlantUML cannot declare E again as class'];
        yield 'note without link' => ['note on link : alone', 'The PlantUML note has no link to stand on: note on link : alone'];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotDrawnNativelyIsRefused(string $line, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($line);
    }

    private static function read(string $lines): PlantUmlClasses
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlClasses::class, $diagram);

        return $diagram;
    }
}
