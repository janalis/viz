<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\Note;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * A native description diagram as a shared model: a use case diagram when it has actors or
 * use cases — its containers as boundaries, `<<include>>` and `<<extend>>` links as theirs,
 * a triangle a generalization — and a graph otherwise, each symbol as the nearest shape.
 */
final class DescriptionModel
{
    public static function of(PlantUmlDescriptions $diagram): Diagram
    {
        $title = $diagram->document->captions['title'] ?? null;
        $title = null === $title ? '' : \implode("\n", $title->lines);
        $usecase = [] !== \array_filter($diagram->elements, static fn (DescriptionElement $element): bool => \in_array($element->symbol, ['actor', 'usecase'], strict: true));

        return $usecase ? self::useCases($diagram, $title) : self::graph($diagram, $title);
    }

    private static function useCases(PlantUmlDescriptions $diagram, string $title): UseCaseDiagram
    {
        $actors = [];
        $useCases = [];
        foreach ($diagram->elements as $element) {
            $label = self::label($element);
            $stereotype = $element->stereotypes[0] ?? null;
            match ($element->symbol) {
                'actor' => $actors[] = new Actor($element->id, $label, $stereotype, $element->business),
                'usecase' => $useCases[] = new UseCase($element->id, $label, $stereotype, $element->business),
                default => null,
            };
        }
        $boundaries = [];
        foreach ($diagram->groups as $group) {
            if ('together' === $group->symbol) {
                continue;
            }
            $members = \array_keys(\array_filter($diagram->elements, static fn (DescriptionElement $element): bool => self::owner($diagram, $element->group) === $group->id));
            $boundaries[] = new Boundary($group->id, $group->display === $group->id ? '' : $group->display, \array_map(\strval(...), $members));
        }

        $notes = [];
        foreach ($diagram->notes as $note) {
            $notes[] = new Note(\implode("\n", $note->lines), $note->entity ?? self::linked($diagram, $note));
        }

        return new UseCaseDiagram(
            $actors,
            $useCases,
            $boundaries,
            \array_map(self::association(...), \array_values(\array_filter($diagram->links, static fn (PlantUmlLink $link): bool => !$link->hidden && !self::noted($diagram, $link)))),
            $notes,
            $diagram->direction,
            $title,
        );
    }

    /** The element a floating note is linked to, as `N .. Element` writes it. */
    private static function linked(PlantUmlDescriptions $diagram, PlantUmlNote $note): ?string
    {
        foreach ($diagram->links as $link) {
            if ($link->from === $note->id || $link->to === $note->id) {
                return $link->from === $note->id ? $link->to : $link->from;
            }
        }

        return null;
    }

    /** Whether a link joins a note to what it is about, rather than two elements. */
    private static function noted(PlantUmlDescriptions $diagram, PlantUmlLink $link): bool
    {
        foreach ($diagram->notes as $note) {
            if ($link->from === $note->id || $link->to === $note->id) {
                return true;
            }
        }

        return false;
    }

    private static function association(PlantUmlLink $link): Association
    {
        $stereotype = \strtolower($link->stereotype ?? \trim((string) $link->label, characters: '<> '));
        $kind = match (true) {
            'include' === $stereotype => AssociationKind::Include,
            'extend' === $stereotype => AssociationKind::Extend,
            LinkDecor::Extends === $link->toDecor => AssociationKind::Generalization,
            default => AssociationKind::Association,
        };
        $label = AssociationKind::Association === $kind ? (string) $link->label : '';

        return new Association($link->from, $link->to, $kind, $label, self::head($link->toDecor), self::head($link->fromDecor));
    }

    private static function graph(PlantUmlDescriptions $diagram, string $title): Graph
    {
        $nodes = [];
        foreach ($diagram->elements as $element) {
            $nodes[] = new Node($element->id, self::label($element), self::shape($element->symbol), ClassColors::back($element->color), ClassColors::line($element->color));
        }
        // A note stands in the graph as a node of its own, linked to what it is about.
        foreach ($diagram->notes as $note) {
            if (null !== $note->link) {
                continue;
            }
            $nodes[] = new Node($note->id, \implode("\n", $note->lines), Shape::Note);
        }
        $edges = \array_map(
            static fn (PlantUmlLink $link): Edge => new Edge($link->from, $link->to, (string) $link->label, self::head($link->toDecor), self::head($link->fromDecor), self::line($link)),
            \array_values(\array_filter($diagram->links, static fn (PlantUmlLink $link): bool => !$link->hidden)),
        );
        foreach ($diagram->notes as $note) {
            if (null === $note->entity) {
                continue;
            }
            $edges[] = new Edge($note->id, $note->entity, head: ArrowHead::None, style: LineStyle::Dotted);
        }

        return new Graph(
            $nodes,
            $edges,
            self::clusters($diagram, null),
            $diagram->direction,
            $title,
        );
    }

    /** @return list<Cluster> */
    private static function clusters(PlantUmlDescriptions $diagram, ?string $parent): array
    {
        $clusters = [];
        foreach ($diagram->groups as $group) {
            if ($group->parent !== $parent) {
                continue;
            }
            if ('together' === $group->symbol) {
                \array_push($clusters, ...self::clusters($diagram, $group->id));
                continue;
            }
            $members = \array_keys(\array_filter($diagram->elements, static fn (DescriptionElement $element): bool => self::owner($diagram, $element->group) === $group->id));
            $clusters[] = new Cluster($group->id, $group->display === $group->id ? '' : $group->display, \array_map(\strval(...), $members), self::clusters($diagram, $group->id));
        }

        return $clusters;
    }

    /** The container an element is in, `together` blocks seen through. */
    private static function owner(PlantUmlDescriptions $diagram, ?string $group): ?string
    {
        while (null !== $group && 'together' === $diagram->groups[$group]->symbol) {
            $group = $diagram->groups[$group]->parent;
        }

        return $group;
    }

    private static function label(DescriptionElement $element): ?string
    {
        $label = \implode("\n", $element->display);

        return $label === $element->id ? null : $label;
    }

    private static function shape(string $symbol): Shape
    {
        return match ($symbol) {
            'interface', 'circle' => Shape::Circle,
            'hexagon' => Shape::Hexagon,
            'database', 'queue', 'storage' => Shape::Cylinder,
            'card', 'person' => Shape::Rounded,
            'file', 'artifact' => Shape::Note,
            default => Shape::Box,
        };
    }

    private static function head(LinkDecor $decor): ArrowHead
    {
        return match ($decor) {
            LinkDecor::Arrow, LinkDecor::ArrowTriangle, LinkDecor::HalfArrowUp, LinkDecor::HalfArrowDown => ArrowHead::Arrow,
            LinkDecor::Extends, LinkDecor::Redefines, LinkDecor::DefinedBy => ArrowHead::Triangle,
            LinkDecor::Composition => ArrowHead::Diamond,
            LinkDecor::Aggregation => ArrowHead::HollowDiamond,
            LinkDecor::Circle, LinkDecor::CircleFill, LinkDecor::CircleConnect, LinkDecor::Parenthesis => ArrowHead::Circle,
            LinkDecor::NotNavigable, LinkDecor::Plus => ArrowHead::Cross,
            default => ArrowHead::None,
        };
    }

    private static function line(PlantUmlLink $link): LineStyle
    {
        return match (true) {
            $link->dotted => LineStyle::Dotted,
            $link->dashed => LineStyle::Dashed,
            $link->bold => LineStyle::Bold,
            default => LineStyle::Solid,
        };
    }
}
