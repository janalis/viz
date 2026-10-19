<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\LineStyle;

/** Entities and their attributes, the relationships between them, and frames around them. */
final readonly class EntityDiagram implements Diagram
{
    /**
     * @param list<Entity>       $entities
     * @param list<Relationship> $relationships
     * @param list<Group>        $groups top-level frames
     */
    public function __construct(
        public array $entities = [],
        public array $relationships = [],
        public array $groups = [],
        public Direction $direction = Direction::TopBottom,
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::Entity;
    }

    /** Entities as boxes listing their attributes, relationships as edges labelled with both cardinalities. */
    #[\Override]
    public function graph(): Graph
    {
        return new Graph(
            \array_map(
                static fn (Entity $entity): Node => new Node($entity->id, $entity->label, compartments: [\array_map(
                    static fn (Attribute $attribute): string => $attribute->text(),
                    $entity->attributes,
                )]),
                $this->entities,
            ),
            \array_map(
                static fn (Relationship $relationship): Edge => new Edge(
                    $relationship->from,
                    $relationship->to,
                    \implode(' ', \array_filter(
                        [$relationship->fromCardinality->value, \trim($relationship->label), $relationship->toCardinality->value],
                        static fn (string $part): bool => '' !== $part,
                    )),
                    ArrowHead::None,
                    style: $relationship->identifying ? LineStyle::Solid : LineStyle::Dashed,
                ),
                $this->relationships,
            ),
            \array_map(self::cluster(...), $this->groups),
            $this->direction,
            $this->title,
        );
    }

    private static function cluster(Group $group): Cluster
    {
        return new Cluster($group->id, $group->label, $group->entities, \array_map(self::cluster(...), $group->groups));
    }
}
