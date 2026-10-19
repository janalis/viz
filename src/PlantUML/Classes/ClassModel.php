<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Classes\Classifier;
use Janalis\Viz\Model\Classes\ClassifierKind;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\Note;
use Janalis\Viz\Model\Classes\Package;
use Janalis\Viz\Model\Classes\Relation;
use Janalis\Viz\Model\Classes\RelationEnd;
use Janalis\Viz\Model\Classes\Visibility;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\PlantUML\Links\LinkDecor;

/**
 * A native PlantUML class diagram as the shared class model: classifiers with their members
 * read from their bodies, links as relations marked at both ends, packages, and notes.
 * `together` blocks lend their classifiers to their parent; hidden links, kept only to steer
 * the layout, are left out.
 */
final class ClassModel
{
    public static function of(PlantUmlClasses $classes): ClassDiagram
    {
        $title = $classes->document->captions['title'] ?? null;

        return new ClassDiagram(
            \array_values(\array_map(self::classifier(...), $classes->entities)),
            \array_values(\array_map(self::relation(...), \array_filter($classes->links, static fn (ClassLink $link): bool => !$link->hidden))),
            self::packages($classes, null),
            \array_map(static fn (ClassNote $note): Note => new Note(\implode("\n", $note->lines), $note->entity), $classes->notes),
            $classes->direction,
            null === $title ? '' : \implode("\n", $title->lines),
        );
    }

    private static function classifier(ClassEntity $entity): Classifier
    {
        $kind = match ($entity->type) {
            'interface' => ClassifierKind::Interface,
            'abstract' => ClassifierKind::Abstract,
            'enum' => ClassifierKind::Enum,
            'annotation' => ClassifierKind::Annotation,
            'entity' => ClassifierKind::Entity,
            default => ClassifierKind::Concrete,
        };
        $stereotypes = \in_array($entity->type, ['protocol', 'struct', 'exception', 'metaclass', 'stereotype', 'dataclass', 'record'], strict: true)
            ? [$entity->type, ...$entity->stereotypes]
            : $entity->stereotypes;
        $label = \implode("\n", $entity->display);

        return new Classifier(
            $entity->id,
            $label === $entity->id ? null : $label,
            $kind,
            $stereotypes,
            $entity->generic,
            \array_values(\array_filter(\array_map(self::member(...), $entity->body))),
        );
    }

    /** A body line as a member; null for a separator or a blank line. */
    public static function member(string $line): ?Member
    {
        $text = \trim($line);
        if ('' === $text || 1 === \preg_match('/^(--|\.\.|==|__)/', $text)) {
            return null;
        }
        $match = [];
        \preg_match('/^((?:\{(?:static|classifier|abstract|field|method)\}\s*)*)(.*)$/is', $text, $match);
        $modifiers = \strtolower($match[1] ?? '');
        $text = $match[2] ?? $text;
        $static = \str_contains($modifiers, '{static}') || \str_contains($modifiers, '{classifier}');
        $abstract = \str_contains($modifiers, '{abstract}');
        $forced = match (true) {
            \str_contains($modifiers, '{method}') => 'method',
            \str_contains($modifiers, '{field}') => 'field',
            default => null,
        };
        $visibility = Visibility::tryFrom($text[0] ?? '') ?? Visibility::Unspecified;
        $text = Visibility::Unspecified === $visibility ? $text : \ltrim(\substr($text, offset: 1));

        return 'method' === $forced || ('field' !== $forced && \str_contains($text, '('))
            ? self::method($text, $visibility, $static, $abstract)
            : self::field($text, $visibility, $static, $abstract);
    }

    private static function method(string $text, Visibility $visibility, bool $static, bool $abstract): Member
    {
        $open = \strpos($text, needle: '(');
        $close = \strrpos($text, needle: ')');
        if (false === $open || false === $close || $close < $open) {
            return new Member($text, $visibility, parameters: '', static: $static, abstract: $abstract);
        }
        $name = \trim(\substr($text, offset: 0, length: $open));
        $after = \trim(\substr($text, $close + 1));
        $type = \str_starts_with($after, ':') ? \trim(\substr($after, offset: 1)) : null;
        $space = \strrpos($name, needle: ' ');
        if (null === $type && false !== $space) {
            // Java style: the return type before the name.
            [$type, $name] = [\trim(\substr($name, offset: 0, length: $space)), \trim(\substr($name, $space + 1))];
        }

        return new Member($name, $visibility, '' === $type ? null : $type, \substr($text, $open + 1, $close - $open - 1), $static, $abstract);
    }

    private static function field(string $text, Visibility $visibility, bool $static, bool $abstract): Member
    {
        $colon = \strpos($text, needle: ':');
        if (false !== $colon) {
            return new Member(\trim(\substr($text, offset: 0, length: $colon)), $visibility, \trim(\substr($text, $colon + 1)), static: $static, abstract: $abstract);
        }
        $space = \strrpos($text, needle: ' ');

        return false === $space
            ? new Member($text, $visibility, static: $static, abstract: $abstract)
            : new Member(\trim(\substr($text, $space + 1)), $visibility, \trim(\substr($text, offset: 0, length: $space)), static: $static, abstract: $abstract);
    }

    private static function relation(ClassLink $link): Relation
    {
        return new Relation(
            $link->from,
            $link->to,
            self::end($link->fromDecor),
            self::end($link->toDecor),
            match (true) {
                $link->dotted => LineStyle::Dotted,
                $link->dashed => LineStyle::Dashed,
                $link->bold => LineStyle::Bold,
                default => LineStyle::Solid,
            },
            $link->label ?? '',
            $link->firstLabel ?? '',
            $link->secondLabel ?? '',
        );
    }

    private static function end(LinkDecor $decor): RelationEnd
    {
        return match ($decor) {
            LinkDecor::Extends, LinkDecor::Redefines, LinkDecor::DefinedBy => RelationEnd::Triangle,
            LinkDecor::Composition => RelationEnd::Diamond,
            LinkDecor::Aggregation => RelationEnd::HollowDiamond,
            LinkDecor::Arrow, LinkDecor::ArrowTriangle, LinkDecor::HalfArrowUp, LinkDecor::HalfArrowDown => RelationEnd::Arrow,
            LinkDecor::NotNavigable => RelationEnd::Cross,
            LinkDecor::Circle, LinkDecor::CircleConnect, LinkDecor::Parenthesis => RelationEnd::Lollipop,
            default => RelationEnd::None,
        };
    }

    /** @return list<Package> the packages inside a group, `together` blocks seen through */
    private static function packages(PlantUmlClasses $classes, ?string $parent): array
    {
        $packages = [];
        foreach ($classes->groups as $group) {
            if ($group->parent !== $parent) {
                continue;
            }
            if ('together' === $group->type) {
                \array_push($packages, ...self::packages($classes, $group->id));
                continue;
            }
            $packages[] = new Package($group->id, $group->display === $group->id ? '' : $group->display, self::members($classes, $group->id), self::packages($classes, $group->id));
        }

        return $packages;
    }

    /** @return list<string> the classifiers a package holds, those of its `together` blocks too */
    private static function members(PlantUmlClasses $classes, string $group): array
    {
        $members = [];
        foreach ($classes->entities as $entity) {
            $owner = $entity->group;
            while (null !== $owner && $owner !== $group && 'together' === $classes->groups[$owner]->type) {
                $owner = $classes->groups[$owner]->parent;
            }
            if ($owner === $group) {
                $members[] = $entity->id;
            }
        }

        return $members;
    }
}
