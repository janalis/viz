<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Classes\Classifier;
use Janalis\Viz\Model\Classes\ClassifierKind;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\Note;
use Janalis\Viz\Model\Classes\Package;
use Janalis\Viz\Model\Classes\Relation;
use Janalis\Viz\Model\Classes\RelationEnd;
use Janalis\Viz\Model\Classes\Visibility;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\LineStyle;

/** One class diagram that uses every part of the shared class model. */
final class ClassSample
{
    public static function diagram(): ClassDiagram
    {
        return new ClassDiagram(
            [
                new Classifier('Shape', kind: ClassifierKind::Interface, generic: 'T', members: [
                    new Member('name', Visibility::Public, 'String'),
                    new Member('area', Visibility::Public, 'double', '', static: true),
                    new Member('resize', Visibility::Private, 'void', 'int factor', abstract: true),
                ]),
                new Classifier('Circle', 'Round "thing"', stereotypes: ['round']),
                new Classifier('Base', kind: ClassifierKind::Abstract, members: [new Member('id')]),
                new Classifier('Color', kind: ClassifierKind::Enum, members: [new Member('RED'), new Member('GREEN')]),
                new Classifier('Marker', kind: ClassifierKind::Annotation),
                new Classifier('Row', kind: ClassifierKind::Entity),
                new Classifier('it', kind: ClassifierKind::Object),
                new Classifier('two words'),
            ],
            [
                new Relation('Shape', 'Circle', RelationEnd::Triangle, RelationEnd::None, LineStyle::Dashed, 'implements'),
                new Relation('Circle', 'two words', RelationEnd::Diamond, RelationEnd::Arrow, label: "logs\nmuch", fromLabel: '1', toLabel: 'many'),
                new Relation('Base', 'Color', RelationEnd::HollowDiamond, RelationEnd::Lollipop),
                new Relation('Row', 'it', RelationEnd::Cross, RelationEnd::Triangle, LineStyle::Bold),
                new Relation('Marker', 'Row', RelationEnd::Arrow, RelationEnd::Diamond, LineStyle::Dotted),
                new Relation('Color', 'Base', RelationEnd::Lollipop, RelationEnd::HollowDiamond),
                new Relation('it', 'Row', RelationEnd::None, RelationEnd::Cross),
            ],
            [new Package('shapes', 'Shapes', ['Shape', 'Circle'], [new Package('shapes.base', '', ['Base'])])],
            [new Note("round\nand round", 'Circle'), new Note('free "note"')],
            Direction::LeftRight,
            'Classes',
        );
    }
}
