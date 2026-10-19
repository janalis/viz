<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Classes\Classifier;
use Janalis\Viz\Model\Classes\ClassifierKind;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\Package;
use Janalis\Viz\Model\Classes\Relation;
use Janalis\Viz\Model\Classes\RelationEnd;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\LineStyle;

/**
 * The shared {@see ClassDiagram} written as a Mermaid `classDiagram`. A kind other than a
 * plain class becomes its annotation, such as `<<interface>>`; packages become namespaces.
 * Mermaid has no cross at a relation's end, which is dropped. Every text stays on its line.
 */
final class MermaidClassWriter
{
    private const string NAME = '/^[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*$/u';

    public static function write(ClassDiagram $diagram): string
    {
        $lines = ['classDiagram'];
        if ('' !== $diagram->title) {
            \array_unshift($lines, '---', 'title: '.\json_encode($diagram->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        if (Direction::TopBottom !== $diagram->direction) {
            $lines[] = '    direction '.$diagram->direction->value;
        }
        $classifiers = [];
        foreach ($diagram->classifiers as $classifier) {
            $classifiers[$classifier->id] = $classifier;
        }
        $owners = $diagram->owners();
        foreach ($diagram->packages as $package) {
            \array_push($lines, ...self::package($package, '', $classifiers, '    '));
        }
        foreach ($diagram->classifiers as $classifier) {
            if (\array_key_exists($classifier->id, $owners)) {
                continue;
            }
            \array_push($lines, ...self::classifier($classifier, '    '));
        }
        foreach ($diagram->relations as $relation) {
            $lines[] = '    '.self::relation($relation);
        }
        foreach ($diagram->notes as $note) {
            $lines[] = '    note '.(null === $note->for ? '' : 'for '.self::id($note->for).' ').'"'.self::text($note->text, '\n').'"';
        }

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Classifier> $classifiers
     * @return list<string>
     */
    private static function package(Package $package, string $parent, array $classifiers, string $indent): array
    {
        $name = '' !== $parent && \str_starts_with($package->id, $parent.'.') ? \substr($package->id, \strlen($parent) + 1) : $package->id;
        $name = 1 === \preg_match(self::NAME, $name) ? $name : (string) \preg_replace('/[^\p{L}\p{N}_-]+/u', replacement: '_', subject: $name);
        $label = '' === $package->label || $package->label === $name ? '' : '["'.self::text($package->label, ' ').'"]';
        $lines = [$indent.'namespace '.$name.$label.' {'];
        foreach ($package->packages as $child) {
            \array_push($lines, ...self::package($child, $package->id, $classifiers, $indent.'    '));
        }
        foreach ($package->classifiers as $id) {
            \array_push($lines, ...self::classifier($classifiers[$id] ?? new Classifier($id), $indent.'    '));
        }
        $lines[] = $indent.'}';

        return $lines;
    }

    /** @return list<string> */
    private static function classifier(Classifier $classifier, string $indent): array
    {
        $head = 'class '.self::id($classifier->id).(null === $classifier->generic ? '' : '~'.self::text($classifier->generic, ' ').'~');
        if (null !== $classifier->label) {
            $head .= '["'.self::text($classifier->label, ' ').'"]';
        }
        $body = [];
        if (ClassifierKind::Concrete !== $classifier->kind) {
            $body[] = '<<'.\array_flip(\array_map(static fn (ClassifierKind $kind): string => $kind->value, MermaidClassReader::KINDS))[$classifier->kind->value].'>>';
        }
        foreach ($classifier->stereotypes as $stereotype) {
            $body[] = '<<'.(string) \preg_replace('/[^\w-]+/', replacement: '_', subject: $stereotype).'>>';
        }
        foreach ($classifier->members as $member) {
            $body[] = self::member($member);
        }
        if ([] === $body) {
            return [$indent.$head];
        }

        return [$indent.$head.' {', ...\array_map(static fn (string $line): string => $indent.'    '.$line, $body), $indent.'}'];
    }

    private static function member(Member $member): string
    {
        $flags = ($member->static ? '$' : '').($member->abstract ? '*' : '');
        $text = $member->method()
            ? $member->name.'('.($member->parameters ?? '').')'.$flags.(null === $member->type ? '' : ' '.$member->type)
            : (null === $member->type ? '' : $member->type.' ').$member->name.$flags;

        return $member->visibility->value.self::text($text, ' ');
    }

    private static function relation(Relation $relation): string
    {
        $from = match ($relation->fromEnd) {
            RelationEnd::Arrow => '<',
            RelationEnd::Triangle => '<|',
            RelationEnd::Diamond => '*',
            RelationEnd::HollowDiamond => 'o',
            RelationEnd::Lollipop => '()',
            RelationEnd::None, RelationEnd::Cross => '',
        };
        $to = match ($relation->toEnd) {
            RelationEnd::Arrow => '>',
            RelationEnd::Triangle => '|>',
            RelationEnd::Diamond => '*',
            RelationEnd::HollowDiamond => 'o',
            RelationEnd::Lollipop => '()',
            RelationEnd::None, RelationEnd::Cross => '',
        };
        $line = LineStyle::Solid === $relation->line || LineStyle::Bold === $relation->line ? '--' : '..';
        $quoted = static fn (string $text): string => '' === $text ? '' : '"'.self::text($text, ' ').'" ';

        return (
            self::id($relation->from)
            .' '
            .$quoted($relation->fromLabel)
            .$from
            .$line
            .$to
            .' '
            .$quoted($relation->toLabel)
            .self::id($relation->to)
            .('' === $relation->label ? '' : ' : '.self::text($relation->label, '<br>'))
        );
    }

    private static function id(string $id): string
    {
        return 1 === \preg_match(self::NAME, $id) ? $id : '`'.\str_replace(['`', "\r", "\n"], replace: '', subject: $id).'`';
    }

    /** One line: quotes and braces as entities, line breaks as $break. */
    private static function text(string $text, string $break): string
    {
        return \str_replace(['"', '{', '}', "\r\n", "\r", "\n"], ['#quot;', '#123;', '#125;', $break, $break, $break], $text);
    }
}
