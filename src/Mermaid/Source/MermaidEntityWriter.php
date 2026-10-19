<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Cardinality;
use Janalis\Viz\Model\Entity\Entity;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Group;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Model\Entity\Relationship;

/**
 * The shared {@see EntityDiagram} written as a Mermaid `erDiagram`: an entity's label as
 * its alias, groups as subgraphs. Every text stays on its line.
 */
final class MermaidEntityWriter
{
    private const string NAME = '/^[\p{L}\p{N}_*.-]+$/u';
    private const string WORD = '/^[\p{L}_*][\p{L}\p{N}_*.,()\[\]-]*$/u';

    public static function write(EntityDiagram $diagram): string
    {
        $lines = ['erDiagram'];
        if ('' !== $diagram->title) {
            \array_unshift($lines, '---', 'title: '.\json_encode($diagram->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        if (Direction::TopBottom !== $diagram->direction) {
            $lines[] = '    direction '.$diagram->direction->value;
        }
        $entities = [];
        foreach ($diagram->entities as $entity) {
            $entities[$entity->id] = $entity;
        }
        $grouped = [];
        foreach ($diagram->groups as $group) {
            \array_push($lines, ...self::group($group, $entities, '    ', $grouped));
        }
        foreach ($diagram->entities as $entity) {
            if (\array_key_exists($entity->id, $grouped)) {
                continue;
            }
            \array_push($lines, ...self::entity($entity, '    '));
        }
        foreach ($diagram->relationships as $relationship) {
            $lines[] = '    '.self::relationship($relationship);
        }

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Entity> $entities
     * @param array<string, true> $grouped the entities written so far
     * @return list<string>
     */
    private static function group(Group $group, array $entities, string $indent, array &$grouped): array
    {
        $id = (string) \preg_replace('/[^\p{L}\p{N}_-]+/u', replacement: '_', subject: $group->id);
        $lines = [$indent.'subgraph '.$id.('' === $group->label ? '' : '["'.self::text($group->label).'"]')];
        foreach ($group->groups as $child) {
            \array_push($lines, ...self::group($child, $entities, $indent.'    ', $grouped));
        }
        foreach ($group->entities as $id) {
            $grouped[$id] = true;
            \array_push($lines, ...self::entity($entities[$id] ?? new Entity($id), $indent.'    '));
        }
        $lines[] = $indent.'end';

        return $lines;
    }

    /** @return list<string> */
    private static function entity(Entity $entity, string $indent): array
    {
        $head = $indent.self::name($entity->id).(null === $entity->label ? '' : '["'.self::text($entity->label).'"]');
        if ([] === $entity->attributes) {
            return [$head];
        }

        return [$head.' {', ...\array_map(static fn (Attribute $attribute): string => $indent.'    '.self::attribute($attribute), $entity->attributes), $indent.'}'];
    }

    private static function attribute(Attribute $attribute): string
    {
        $keys = [] === $attribute->keys ? '' : ' '.\implode(',', \array_map(static fn (Key $key): string => $key->value, $attribute->keys));
        $comment = '' === $attribute->comment ? '' : ' "'.self::text($attribute->comment).'"';

        return self::word($attribute->type).' '.self::word($attribute->name).$keys.$comment;
    }

    private static function relationship(Relationship $relationship): string
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

        return self::name($relationship->from).' '.$left.($relationship->identifying ? '--' : '..').$right.' '.self::name($relationship->to).' : "'.self::text($relationship->label).'"';
    }

    private static function name(string $id): string
    {
        return 1 === \preg_match(self::NAME, $id) ? $id : '"'.self::text($id).'"';
    }

    private static function word(string $word): string
    {
        return 1 === \preg_match(self::WORD, $word) ? $word : '`'.\str_replace(['`', "\r", "\n"], replace: '', subject: $word).'`';
    }

    /** One line: quotes as entities, line breaks as `<br>`. */
    private static function text(string $text): string
    {
        return \str_replace(['"', "\r\n", "\r", "\n"], ['#quot;', '<br>', '<br>', '<br>'], $text);
    }
}
