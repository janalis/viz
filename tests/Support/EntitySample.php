<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Cardinality;
use Janalis\Viz\Model\Entity\Entity;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Group;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Model\Entity\Relationship;

/** One entity-relationship diagram that uses every part of the shared entity model. */
final class EntitySample
{
    public static function diagram(): EntityDiagram
    {
        return new EntityDiagram(
            [
                new Entity('CUSTOMER', 'Client "VIP"', [new Attribute('string', 'id', [Key::Primary]), new Attribute('string', 'full name', comment: "legal\nname")]),
                new Entity('ORDER', attributes: [new Attribute('int', 'number', [Key::Primary, Key::Foreign, Key::Unique])]),
                new Entity('LINE ITEM'),
                new Entity('PRODUCT'),
            ],
            [
                new Relationship('CUSTOMER', 'ORDER', Cardinality::ExactlyOne, Cardinality::ZeroOrMore, label: 'places'),
                new Relationship('ORDER', 'LINE ITEM', Cardinality::ZeroOrOne, Cardinality::OneOrMore, identifying: false, label: "contains\nlines"),
                new Relationship('PRODUCT', 'LINE ITEM', Cardinality::OneOrMore, Cardinality::ZeroOrOne),
                new Relationship('PRODUCT', 'PRODUCT', Cardinality::ZeroOrMore, Cardinality::ExactlyOne, label: ' '),
            ],
            [new Group('sales', 'Sales', ['ORDER'], [new Group('items', '', ['LINE ITEM'])])],
            Direction::LeftRight,
            'Shop',
        );
    }
}
