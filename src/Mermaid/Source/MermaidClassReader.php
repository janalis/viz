<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\ClassDiagram as MermaidClass;
use Janalis\Viz\Mermaid\ClassLexer;
use Janalis\Viz\Mermaid\ClassParser;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
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

/**
 * A Mermaid class diagram read into the shared {@see ClassDiagram}: members parsed into
 * name, visibility, type and parameters; the annotations that name a kind, such as
 * `<<interface>>`, read as that kind. Styles, classes, clicks and links are dropped.
 */
final class MermaidClassReader
{
    /** Annotations that name a classifier's kind rather than a stereotype. */
    public const array KINDS = [
        'interface' => ClassifierKind::Interface,
        'abstract' => ClassifierKind::Abstract,
        'enumeration' => ClassifierKind::Enum,
        'annotation' => ClassifierKind::Annotation,
        'entity' => ClassifierKind::Entity,
        'object' => ClassifierKind::Object,
    ];

    private const string NAMESPACE = 'namespace:';

    /**
     * @throws MermaidSyntaxError when the source is not a valid class diagram
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): ClassDiagram
    {
        $prepared = MermaidPrepared::of($source);
        $parser = new ClassParser();
        foreach (new ClassLexer($prepared->source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $classifiers = [];
        $owners = [];
        foreach ($diagram->graph->nodes as $id => $node) {
            $id = (string) $id;
            $classifiers[] = self::classifier($diagram, $id, $node['label']);
            $owner = \array_last($node['groups']);
            if (null !== $owner) {
                $owners[$owner][] = $id;
            }
        }
        $relations = [];
        foreach ($diagram->graph->edges as $index => $edge) {
            $labels = $diagram->labels[$index];
            $relations[] = new Relation(
                $edge['from'],
                $edge['to'],
                self::end($diagram->markers[$index]['left']),
                self::end($diagram->markers[$index]['right']),
                '-.-' === $edge['arrow'] ? LineStyle::Dashed : LineStyle::Solid,
                $labels['label'],
                $labels['left'],
                $labels['right'],
            );
        }
        $notes = \array_map(static fn (array $note): Note => new Note($note['text'], '' === $note['for'] ? null : $note['for']), $diagram->notes);

        return new ClassDiagram($classifiers, $relations, self::packages($diagram, $owners, parent: null), $notes, Direction::from($diagram->graph->direction), $prepared->title);
    }

    /** One member as Mermaid writes it: `+name(parameters) Type`, `+Type name`, with `$` for static and `*` for abstract. */
    public static function member(string $text): Member
    {
        $text = \trim($text);
        $visibility = Visibility::tryFrom($text[0] ?? '') ?? Visibility::Unspecified;
        $text = \ltrim(\substr($text, \strlen($visibility->value)));
        $static = false;
        $abstract = false;
        $open = \strpos($text, needle: '(');
        $close = \strrpos($text, needle: ')');
        if (false !== $open && false !== $close && $close > $open) {
            $rest = \trim(\substr($text, $close + 1));
            while ('' !== $rest && \in_array($rest[0], ['$', '*'], strict: true)) {
                [$static, $abstract] = [$static || '$' === $rest[0], $abstract || '*' === $rest[0]];
                $rest = \ltrim(\substr($rest, offset: 1));
            }
            while ('' !== $rest && \in_array($rest[-1], ['$', '*'], strict: true)) {
                [$static, $abstract] = [$static || '$' === $rest[-1], $abstract || '*' === $rest[-1]];
                $rest = \rtrim(\substr($rest, offset: 0, length: -1));
            }

            return new Member(\trim(\substr($text, offset: 0, length: $open)), $visibility, '' === $rest ? null : $rest, \substr($text, $open + 1, $close - $open - 1), $static, $abstract);
        }
        while ('' !== $text && \in_array($text[-1], ['$', '*'], strict: true)) {
            [$static, $abstract] = [$static || '$' === $text[-1], $abstract || '*' === $text[-1]];
            $text = \rtrim(\substr($text, offset: 0, length: -1));
        }
        $space = \strrpos($text, needle: ' ');

        return false === $space
            ? new Member($text, $visibility, static: $static, abstract: $abstract)
            : new Member(\substr($text, $space + 1), $visibility, \rtrim(\substr($text, offset: 0, length: $space)), static: $static, abstract: $abstract);
    }

    private static function classifier(MermaidClass $diagram, string $id, string $label): Classifier
    {
        $kind = ClassifierKind::Concrete;
        $stereotypes = [];
        foreach ($diagram->annotations[$id] ?? [] as $annotation) {
            $named = self::KINDS[\strtolower($annotation)] ?? null;
            if (null !== $named && ClassifierKind::Concrete === $kind) {
                $kind = $named;
                continue;
            }
            $stereotypes[] = $annotation;
        }
        $generic = $diagram->types[$id] ?? '';

        return new Classifier(
            $id,
            $label === $id ? null : $label,
            $kind,
            $stereotypes,
            '' === $generic ? null : $generic,
            \array_map(static fn (array $member): Member => self::member($member['text']), $diagram->members[$id] ?? []),
        );
    }

    /**
     * @param array<array-key, list<string>> $owners
     * @return list<Package>
     */
    private static function packages(MermaidClass $diagram, array $owners, ?string $parent): array
    {
        $packages = [];
        foreach ($diagram->graph->groups as $id => $group) {
            $id = (string) $id;
            if ($group['parent'] !== $parent) {
                continue;
            }
            $path = \substr($id, \strlen(self::NAMESPACE));
            $name = \substr($path, (int) \strrpos('.'.$path, needle: '.'));
            $packages[] = new Package($path, $group['label'] === $name ? '' : $group['label'], $owners[$id] ?? [], self::packages($diagram, $owners, $id));
        }

        return $packages;
    }

    private static function end(string $marker): RelationEnd
    {
        return match ($marker) {
            '<', '>' => RelationEnd::Arrow,
            '<|', '|>' => RelationEnd::Triangle,
            '*' => RelationEnd::Diamond,
            'o' => RelationEnd::HollowDiamond,
            '()' => RelationEnd::Lollipop,
            default => RelationEnd::None,
        };
    }
}
