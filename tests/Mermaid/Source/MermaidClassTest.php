<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\Source\MermaidClassReader;
use Janalis\Viz\Mermaid\Source\MermaidClassWriter;
use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Classes\ClassifierKind;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\RelationEnd;
use Janalis\Viz\Model\Classes\Visibility;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Tests\Support\ClassSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidClassTest extends TestCase
{
    public function testAClassDiagramIsReadIntoTheSharedModel(): void
    {
        $diagram = new MermaidReader()->read(<<<'MMD'
            ---
            title: Shapes
            ---
            classDiagram
              direction LR
              namespace shapes["Shape kinds"] {
                class Shape~T~ {
                  <<interface>>
                  <<sealed>>
                  +String name
                  +area()$ double
                }
                namespace round {
                  class Circle["Round"]
                }
              }
              class Logger
              Shape <|.. Circle : implements
              Circle "1" *-- "many" Logger : logs
              Logger o--() Shape
              Logger <-- Circle
              note for Circle "round\nand round"
              note "free"
            MMD);

        $this->assertInstanceOf(ClassDiagram::class, $diagram);
        $this->assertSame([Direction::LeftRight, 'Shapes'], [$diagram->direction, $diagram->title]);
        $shape = $diagram->classifiers[0];
        $this->assertSame([ClassifierKind::Interface, ['sealed'], 'T'], [$shape->kind, $shape->stereotypes, $shape->generic]);
        $this->assertEquals([new Member('name', Visibility::Public, 'String'), new Member('area', Visibility::Public, 'double', '', static: true)], $shape->members);
        $this->assertSame('Round', $diagram->classifiers[1]->label);
        $this->assertNull($diagram->classifiers[2]->label);
        $this->assertSame(['shapes', 'Shape kinds', ['Shape']], [$diagram->packages[0]->id, $diagram->packages[0]->label, $diagram->packages[0]->classifiers]);
        $this->assertSame(['shapes.round', '', ['Circle']], [$diagram->packages[0]->packages[0]->id, $diagram->packages[0]->packages[0]->label, $diagram->packages[0]->packages[0]->classifiers]);
        $relation = $diagram->relations[1];
        $this->assertSame(['1', 'many', 'logs', RelationEnd::Diamond, RelationEnd::None], [$relation->fromLabel, $relation->toLabel, $relation->label, $relation->fromEnd, $relation->toEnd]);
        $this->assertSame([RelationEnd::Triangle, LineStyle::Dashed], [$diagram->relations[0]->fromEnd, $diagram->relations[0]->line]);
        $this->assertSame([RelationEnd::HollowDiamond, RelationEnd::Lollipop], [$diagram->relations[2]->fromEnd, $diagram->relations[2]->toEnd]);
        $this->assertSame(RelationEnd::Arrow, $diagram->relations[3]->fromEnd);
        $this->assertSame(["round\nand round", 'Circle'], [$diagram->notes[0]->text, $diagram->notes[0]->for]);
        $this->assertNull($diagram->notes[1]->for);
    }

    public function testMembersReadTheWaysMermaidWritesThem(): void
    {
        $this->assertEquals(new Member('go', Visibility::Private, 'int', 'a, b', static: true, abstract: true), MermaidClassReader::member('-go(a, b)$* int'));
        $this->assertEquals(new Member('go', Visibility::Unspecified, 'int', '', static: true), MermaidClassReader::member('go() int$'));
        $this->assertEquals(new Member('count', Visibility::Package, 'List~int~', static: true), MermaidClassReader::member('~List~int~ count$'));
        $this->assertEquals(new Member('RED'), MermaidClassReader::member('RED'));
    }

    public function testTheSharedModelIsWrittenAsAClassDiagram(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Classes"
            ---
            classDiagram
                direction LR
                namespace shapes["Shapes"] {
                    namespace base {
                        class Base {
                            <<abstract>>
                            id
                        }
                    }
                    class Shape~T~ {
                        <<interface>>
                        +String name
                        +area()$ double
                        -resize(int factor)* void
                    }
                    class Circle["Round #quot;thing#quot;"] {
                        <<round>>
                    }
                }
                class Color {
                    <<enumeration>>
                    RED
                    GREEN
                }
                class Marker {
                    <<annotation>>
                }
                class Row {
                    <<entity>>
                }
                class it {
                    <<object>>
                }
                class `two words`
                Shape <|.. Circle : implements
                Circle "1" *--> "many" `two words` : logs<br>much
                Base o--() Color
                Row --|> it
                Marker <..* Row
                Color ()--o Base
                it -- Row
                note for Circle "round\nand round"
                note "free #quot;note#quot;"

            MMD, MermaidClassWriter::write(ClassSample::diagram()));
    }

    #[DataProvider('corpus')]
    public function testWritingWhatWasReadReachesAFixedPoint(string $file): void
    {
        $first = MermaidClassWriter::write(MermaidClassReader::read((string) \file_get_contents($file)));

        $this->assertSame($first, MermaidClassWriter::write(MermaidClassReader::read($first)));
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        $files = \glob(__DIR__.'/../../fixtures/mermaid/{class,class-members,upstream/class-diagram}/*.mmd', \GLOB_BRACE);
        foreach (false === $files ? [] : $files as $file) {
            yield \basename(\dirname($file)).'/'.\basename($file) => [$file];
        }
    }
}
