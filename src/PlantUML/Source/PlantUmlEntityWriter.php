<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Cardinality;
use Janalis\Viz\Model\Entity\Entity;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Group;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Model\Entity\Relationship;

/**
 * The shared {@see EntityDiagram} written as PlantUML entities in information-engineering
 * notation: keys as stereotypes after each attribute, groups as packages. PlantUML lays
 * out only top-down or left-to-right; an id it would misread is renamed `e0`, `e1`… and
 * keeps its name as the label. Every text stays on its line.
 */
final class PlantUmlEntityWriter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public static function write(EntityDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::text($diagram->title);
        }
        if (\in_array($diagram->direction, [Direction::LeftRight, Direction::RightLeft], strict: true)) {
            $lines[] = 'left to right direction';
        }
        $entities = [];
        foreach ($diagram->entities as $entity) {
            $entities[$entity->id] = $entity;
        }
        $grouped = [];
        foreach ($diagram->groups as $group) {
            \array_push($lines, ...self::group($group, $entities, $ids, '', $grouped));
        }
        foreach ($diagram->entities as $entity) {
            if (\array_key_exists($entity->id, $grouped)) {
                continue;
            }
            \array_push($lines, ...self::entity($entity, $ids, ''));
        }
        foreach ($diagram->relationships as $relationship) {
            $lines[] = self::relationship($relationship, $ids);
        }
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Entity> $entities
     * @param array<string, string> $ids
     * @param array<string, true> $grouped the entities written so far
     * @return list<string>
     */
    private static function group(Group $group, array $entities, array $ids, string $indent, array &$grouped): array
    {
        $lines = [$indent.'package "'.self::text('' === $group->label ? $group->id : $group->label).'" {'];
        foreach ($group->groups as $child) {
            \array_push($lines, ...self::group($child, $entities, $ids, $indent.'  ', $grouped));
        }
        foreach ($group->entities as $id) {
            $grouped[$id] = true;
            \array_push($lines, ...self::entity($entities[$id] ?? new Entity($id), $ids, $indent.'  '));
        }
        $lines[] = $indent.'}';

        return $lines;
    }

    /**
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function entity(Entity $entity, array $ids, string $indent): array
    {
        $id = $ids[$entity->id] ?? $entity->id;
        $named = null !== $entity->label || $id !== $entity->id;
        $head = $indent.'entity '.($named ? '"'.self::text($entity->text()).'" as ' : '').$id;
        if ([] === $entity->attributes) {
            return [$head];
        }

        return [$head.' {', ...\array_map(static fn (Attribute $attribute): string => $indent.'  '.self::attribute($attribute), $entity->attributes), $indent.'}'];
    }

    private static function attribute(Attribute $attribute): string
    {
        $mandatory = \in_array(Key::Primary, $attribute->keys, strict: true) ? '* ' : '';
        $keys = \implode('', \array_map(static fn (Key $key): string => ' <<'.$key->value.'>>', $attribute->keys));

        $line = self::text($attribute->name.' : '.$attribute->type).$keys.('' === $attribute->comment ? '' : ' // '.self::text($attribute->comment));

        // PlantUML takes a member written with a parenthesis, as `string(2)`, for a method, unless marked a field.
        return (\str_contains($line, '(') ? '{field} ' : '').$mandatory.$line;
    }

    /** @param array<string, string> $ids */
    private static function relationship(Relationship $relationship, array $ids): string
    {
        $left = match ($relationship->fromCardinality) {
            Cardinality::ExactlyOne => '||',
            Cardinality::ZeroOrOne => '|o',
            Cardinality::ZeroOrMore => '}o',
            Cardinality::OneOrMore => '}|',
        };
        $right = match ($relationship->toCardinality) {
            Cardinality::ExactlyOne => '||',
            Cardinality::ZeroOrOne => 'o|',
            Cardinality::ZeroOrMore => 'o{',
            Cardinality::OneOrMore => '|{',
        };

        return (
            ($ids[$relationship->from] ?? $relationship->from)
            .' '
            .$left
            .($relationship->identifying ? '--' : '..')
            .$right
            .' '
            .($ids[$relationship->to] ?? $relationship->to)
            .('' === \trim($relationship->label) ? '' : ' : '.self::text(\trim($relationship->label)))
        );
    }

    /** @return array<string, string> */
    private static function identifiers(EntityDiagram $diagram): array
    {
        $all = \array_map(static fn (Entity $entity): string => $entity->id, $diagram->entities);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match(self::NAME, $id)) {
                $ids[$id] = $id;
                continue;
            }
            do {
                $alias = 'e'.$renamed++;
            } while (\in_array($alias, $all, strict: true));
            $ids[$id] = $alias;
        }

        return $ids;
    }

    /** One line: quotes as apostrophes, line breaks as PlantUML's `\n`. */
    private static function text(string $text): string
    {
        return \str_replace(['"', "\r\n", "\r", "\n"], ["'", '\n', '\n', '\n'], $text);
    }
}
