<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\ErDiagram;
use Janalis\Viz\Mermaid\ErLexer;
use Janalis\Viz\Mermaid\ErParser;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Cardinality;
use Janalis\Viz\Model\Entity\Entity;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Group;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Model\Entity\Relationship;

/**
 * A Mermaid entity-relationship diagram read into the shared {@see EntityDiagram}. An
 * entity's alias is its label; the `u` cardinality reads as exactly one. Styles and
 * classes are dropped.
 */
final class MermaidEntityReader
{
    /**
     * @throws MermaidSyntaxError when the source is not a valid entity-relationship diagram
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): EntityDiagram
    {
        $prepared = MermaidPrepared::of($source);
        $parser = new ErParser();
        foreach (new ErLexer($prepared->source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $entities = [];
        $owners = [];
        foreach ($diagram->graph->nodes as $id => $node) {
            $id = (string) $id;
            $entities[] = new Entity($id, $diagram->aliases[$id] ?? null, \array_map(
                static fn (array $attribute): Attribute => new Attribute(
                    $attribute['cells'][0],
                    $attribute['cells'][1],
                    '' === $attribute['cells'][2] ? [] : \array_map(Key::from(...), \explode(',', $attribute['cells'][2])),
                    $attribute['cells'][3],
                ),
                $diagram->attributes[$id] ?? [],
            ));
            $owner = \array_last($node['groups']);
            if (null !== $owner) {
                $owners[$owner][] = $id;
            }
        }
        $relationships = [];
        foreach ($diagram->graph->edges as $index => $edge) {
            $relationships[] = new Relationship(
                $edge['from'],
                $edge['to'],
                self::cardinality($diagram->cardinalities[$index]['left']),
                self::cardinality($diagram->cardinalities[$index]['right']),
                '--' === $edge['arrow'],
                $diagram->roles[$index],
            );
        }

        return new EntityDiagram($entities, $relationships, self::groups($diagram, $owners, parent: null), Direction::from($diagram->graph->direction), $prepared->title);
    }

    /**
     * @param array<array-key, list<string>> $owners
     * @return list<Group>
     */
    private static function groups(ErDiagram $diagram, array $owners, ?string $parent): array
    {
        $groups = [];
        foreach ($diagram->graph->groups as $id => $group) {
            if ($group['parent'] !== $parent) {
                continue;
            }
            $id = (string) $id;
            $groups[] = new Group($id, $group['label'] === $id ? '' : $group['label'], $owners[$id] ?? [], self::groups($diagram, $owners, $id));
        }

        return $groups;
    }

    private static function cardinality(string $card): Cardinality
    {
        return match ($card) {
            '0..1' => Cardinality::ZeroOrOne,
            '0..*' => Cardinality::ZeroOrMore,
            '1..*' => Cardinality::OneOrMore,
            default => Cardinality::ExactlyOne,
        };
    }
}
