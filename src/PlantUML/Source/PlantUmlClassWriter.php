<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

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
 * The shared {@see ClassDiagram} written as a PlantUML class diagram. PlantUML lays out
 * only top-down or left-to-right, and has no lollipop at a relation's end, which is
 * dropped. An id PlantUML would misread is renamed `c0`, `c1`… and keeps its name as the
 * label. Every text stays on its line.
 */
final class PlantUmlClassWriter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public static function write(ClassDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::text($diagram->title);
        }
        if (\in_array($diagram->direction, [Direction::LeftRight, Direction::RightLeft], strict: true)) {
            $lines[] = 'left to right direction';
        }
        $classifiers = [];
        foreach ($diagram->classifiers as $classifier) {
            $classifiers[$classifier->id] = $classifier;
        }
        $owners = $diagram->owners();
        foreach ($diagram->packages as $package) {
            \array_push($lines, ...self::package($package, $classifiers, $ids, ''));
        }
        foreach ($diagram->classifiers as $classifier) {
            if (\array_key_exists($classifier->id, $owners)) {
                continue;
            }
            \array_push($lines, ...self::classifier($classifier, $ids, ''));
        }
        foreach ($diagram->relations as $relation) {
            $lines[] = self::relation($relation, $ids);
        }
        foreach ($diagram->notes as $index => $note) {
            $lines[] = 'note "'.self::text($note->text).'" as N'.$index;
            if (null !== $note->for) {
                $lines[] = 'N'.$index.' .. '.($ids[$note->for] ?? $note->for);
            }
        }
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Classifier> $classifiers
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function package(Package $package, array $classifiers, array $ids, string $indent): array
    {
        $lines = [$indent.'package "'.self::text($package->text()).'" {'];
        foreach ($package->packages as $child) {
            \array_push($lines, ...self::package($child, $classifiers, $ids, $indent.'  '));
        }
        foreach ($package->classifiers as $id) {
            \array_push($lines, ...self::classifier($classifiers[$id] ?? new Classifier($id), $ids, $indent.'  '));
        }
        $lines[] = $indent.'}';

        return $lines;
    }

    /**
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function classifier(Classifier $classifier, array $ids, string $indent): array
    {
        $keyword = match ($classifier->kind) {
            ClassifierKind::Concrete => 'class',
            ClassifierKind::Interface => 'interface',
            ClassifierKind::Abstract => 'abstract class',
            ClassifierKind::Enum => 'enum',
            ClassifierKind::Annotation => 'annotation',
            ClassifierKind::Entity => 'entity',
            ClassifierKind::Object => 'object',
        };
        $id = $ids[$classifier->id] ?? $classifier->id;
        $named = null !== $classifier->label || $id !== $classifier->id;
        $head = $keyword.' '.($named ? '"'.self::text($classifier->text()).'" as ' : '').$id;
        $head .= null === $classifier->generic ? '' : '<'.self::text(\str_replace(['<', '>'], ['‹', '›'], $classifier->generic)).'>';
        foreach ($classifier->stereotypes as $stereotype) {
            $head .= ' <<'.self::text(\str_replace(['<', '>'], replace: '', subject: $stereotype)).'>>';
        }
        if ([] === $classifier->members) {
            return [$indent.$head];
        }

        return [$indent.$head.' {', ...\array_map(static fn (Member $member): string => $indent.'  '.self::member($member), $classifier->members), $indent.'}'];
    }

    private static function member(Member $member): string
    {
        $type = null === $member->type ? '' : ' : '.\str_replace('~', replace: '', subject: $member->type);
        $text = $member->method() ? $member->name.'('.($member->parameters ?? '').')'.$type : $member->name.$type;

        return ($member->static ? '{static} ' : '').($member->abstract ? '{abstract} ' : '').$member->visibility->value.self::text($text);
    }

    /** @param array<string, string> $ids */
    private static function relation(Relation $relation, array $ids): string
    {
        $from = match ($relation->fromEnd) {
            RelationEnd::Arrow => '<',
            RelationEnd::Triangle => '<|',
            RelationEnd::Diamond => '*',
            RelationEnd::HollowDiamond => 'o',
            RelationEnd::Cross => 'x',
            RelationEnd::None, RelationEnd::Lollipop => '',
        };
        $to = match ($relation->toEnd) {
            RelationEnd::Arrow => '>',
            RelationEnd::Triangle => '|>',
            RelationEnd::Diamond => '*',
            RelationEnd::HollowDiamond => 'o',
            RelationEnd::Cross => 'x',
            RelationEnd::None, RelationEnd::Lollipop => '',
        };
        $line = LineStyle::Solid === $relation->line || LineStyle::Bold === $relation->line ? '--' : '..';
        $quoted = static fn (string $text): string => '' === $text ? '' : '"'.self::text($text).'" ';

        return (
            ($ids[$relation->from] ?? $relation->from)
            .' '
            .$quoted($relation->fromLabel)
            .$from
            .$line
            .$to
            .' '
            .$quoted($relation->toLabel)
            .($ids[$relation->to] ?? $relation->to)
            .('' === $relation->label ? '' : ' : '.self::text($relation->label))
        );
    }

    /** @return array<string, string> */
    private static function identifiers(ClassDiagram $diagram): array
    {
        $all = \array_map(static fn (Classifier $classifier): string => $classifier->id, $diagram->classifiers);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match(self::NAME, $id)) {
                $ids[$id] = $id;
                continue;
            }
            do {
                $alias = 'c'.$renamed++;
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
