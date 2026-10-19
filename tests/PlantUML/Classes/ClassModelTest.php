<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Classes;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Classes\ClassifierKind;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\Note;
use Janalis\Viz\Model\Classes\Package;
use Janalis\Viz\Model\Classes\RelationEnd;
use Janalis\Viz\Model\Classes\Visibility;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\PlantUML\Classes\ClassModel;
use Janalis\Viz\PlantUML\PlantUmlReader;
use PHPUnit\Framework\TestCase;

final class ClassModelTest extends TestCase
{
    public function testClassifiersRelationsPackagesAndNotesMapOntoTheSharedModel(): void
    {
        $diagram = self::read(<<<'PUML'
            title Zoo
            left to right direction
            package zoo {
              together {
                abstract class Animal<T> <<base>>
              }
              package inner {
                interface Pet
              }
            }
            enum Color
            annotation Marked
            entity Row
            protocol Talker
            class "Shown name" as Shown
            circle Dot
            Animal <|-- Dog : is
            Pet <|.. Dog
            Dog "1" *-- "4" Leg
            Dog o-- Tail
            Dog --> Food
            Dog ..> Toy
            Dog x-- Cage
            Dog -[bold]-> Ball
            Dog -[dotted]- Bone
            Dog --( Socket
            Dog -[hidden]- Collar
            Dog }-- Fleas
            note right of Dog : good boy
            note "free" as Free
            PUML);

        $this->assertSame('Zoo', $diagram->title);
        $this->assertSame(Direction::LeftRight, $diagram->direction);
        $kinds = [];
        foreach ($diagram->classifiers as $classifier) {
            $kinds[$classifier->id] = [$classifier->kind, $classifier->stereotypes, $classifier->label];
        }
        $this->assertSame([ClassifierKind::Abstract, ['base'], null], $kinds['Animal']);
        $this->assertSame(ClassifierKind::Interface, $kinds['Pet'][0]);
        $this->assertSame(ClassifierKind::Enum, $kinds['Color'][0]);
        $this->assertSame(ClassifierKind::Annotation, $kinds['Marked'][0]);
        $this->assertSame(ClassifierKind::Entity, $kinds['Row'][0]);
        $this->assertSame([ClassifierKind::Concrete, ['protocol'], null], $kinds['Talker']);
        $this->assertSame([ClassifierKind::Concrete, [], 'Shown name'], $kinds['Shown']);
        $this->assertSame([ClassifierKind::Concrete, [], null], $kinds['Dot']);
        $this->assertSame('T', $diagram->classifiers[0]->generic);
        $ends = \array_map(static fn ($relation): array => [$relation->from, $relation->to, $relation->fromEnd, $relation->toEnd, $relation->line], $diagram->relations);
        $this->assertSame(
            [
                ['Animal', 'Dog',    RelationEnd::Triangle,      RelationEnd::None,     LineStyle::Solid],
                ['Pet',    'Dog',    RelationEnd::Triangle,      RelationEnd::None,     LineStyle::Dashed],
                ['Dog',    'Leg',    RelationEnd::Diamond,       RelationEnd::None,     LineStyle::Solid],
                ['Dog',    'Tail',   RelationEnd::HollowDiamond, RelationEnd::None,     LineStyle::Solid],
                ['Dog',    'Food',   RelationEnd::None,          RelationEnd::Arrow,    LineStyle::Solid],
                ['Dog',    'Toy',    RelationEnd::None,          RelationEnd::Arrow,    LineStyle::Dashed],
                ['Dog',    'Cage',   RelationEnd::Cross,         RelationEnd::None,     LineStyle::Solid],
                ['Dog',    'Ball',   RelationEnd::None,          RelationEnd::Arrow,    LineStyle::Bold],
                ['Dog',    'Bone',   RelationEnd::None,          RelationEnd::None,     LineStyle::Dotted],
                ['Dog',    'Socket', RelationEnd::None,          RelationEnd::Lollipop, LineStyle::Solid],
                ['Dog',    'Fleas',  RelationEnd::None,          RelationEnd::None,     LineStyle::Solid],
            ],
            $ends,
            'the hidden link left out; a crow\'s foot has no shared mark',
        );
        $this->assertSame(['is', '1', '4'], [$diagram->relations[0]->label, $diagram->relations[2]->fromLabel, $diagram->relations[2]->toLabel]);
        $this->assertEquals([new Package('zoo', '', ['Animal'], [new Package('inner', '', ['Pet'])])], $diagram->packages);
        $this->assertEquals([new Note('good boy', 'Dog'), new Note('free')], $diagram->notes);
    }

    public function testMembersReadInEitherOrderWithModifiers(): void
    {
        $this->assertNull(ClassModel::member('  '));
        $this->assertNull(ClassModel::member('-- separator --'));
        $this->assertEquals(new Member('name', Visibility::Private, 'String'), ClassModel::member('- name : String'));
        $this->assertEquals(new Member('count', Visibility::Unspecified, 'int', static: true), ClassModel::member('{static} int count'));
        $this->assertEquals(new Member('flag'), ClassModel::member('flag'));
        $this->assertEquals(new Member('run', Visibility::Public, 'void', '', abstract: true), ClassModel::member('{abstract} + run() : void'));
        $this->assertEquals(new Member('add', Visibility::Protected, 'int', 'int a, int b'), ClassModel::member('#int add(int a, int b)'));
        $this->assertEquals(new Member('call', Visibility::Package, parameters: 'x'), ClassModel::member('~call(x)'));
        $this->assertEquals(new Member('weird', parameters: ''), ClassModel::member('{method} weird'));
        $this->assertEquals(new Member('f(x)', type: 'int'), ClassModel::member('{field} f(x) : int'));
        $this->assertEquals(new Member('broken )(', parameters: ''), ClassModel::member('broken )('));
    }

    private static function read(string $lines): ClassDiagram
    {
        $diagram = new PlantUmlReader()->read("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(ClassDiagram::class, $diagram);

        return $diagram;
    }
}
