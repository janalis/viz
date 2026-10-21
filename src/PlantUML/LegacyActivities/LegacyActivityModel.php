<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * A legacy activity diagram as the shared graph model, as it is drawn: activities as rounded
 * boxes, the start a black disc and the ends ringed ones, branches as diamonds, bars as black
 * boxes, notes as notes, partitions as clusters.
 */
final class LegacyActivityModel
{
    public static function of(PlantUmlLegacyActivity $diagram): Graph
    {
        $title = $diagram->document->captions['title'] ?? null;
        $nodes = [];
        foreach ($diagram->entities as $entity) {
            $nodes[] = match ($entity->kind) {
                'start' => new Node($entity->id, '', Shape::Circle, '#000000'),
                'end' => new Node($entity->id, '', Shape::Circle, '#000000', '#ffffff'),
                'branch' => new Node($entity->id, '', Shape::Diamond),
                'bar' => new Node($entity->id, '', Shape::Box, '#000000'),
                'note' => new Node($entity->id, \implode("\n", $entity->display), Shape::Note, ClassColors::back($entity->color)),
                default => new Node($entity->id, self::label($entity), Shape::Rounded, ClassColors::back($entity->color)),
            };
        }
        $edges = \array_map(static fn (PlantUmlLink $link): Edge => new Edge(
            $link->from,
            $link->to,
            \trim(\implode(' ', [(string) $link->label, (string) $link->secondLabel])),
            LinkDecor::Arrow === $link->toDecor ? ArrowHead::Arrow : ArrowHead::None,
            LinkDecor::Arrow === $link->fromDecor ? ArrowHead::Arrow : ArrowHead::None,
            $link->dashed ? LineStyle::Dashed : LineStyle::Solid,
        ), \array_values(\array_filter($diagram->links, static fn (PlantUmlLink $link): bool => !$link->hidden)));

        return new Graph($nodes, $edges, self::clusters($diagram, null), $diagram->direction, null === $title ? '' : \implode("\n", $title->lines));
    }

    /** @return list<Cluster> */
    private static function clusters(PlantUmlLegacyActivity $diagram, ?string $parent): array
    {
        $clusters = [];
        foreach ($diagram->groups as $group) {
            if ($group->parent !== $parent) {
                continue;
            }
            $members = \array_keys(\array_filter($diagram->entities, static fn (LegacyEntity $entity): bool => $entity->group === $group->id));
            $clusters[] = new Cluster($group->id, $group->display, \array_map(\strval(...), $members), self::clusters($diagram, $group->id));
        }

        return $clusters;
    }

    private static function label(LegacyEntity $entity): ?string
    {
        $label = \implode("\n", $entity->display);

        return $label === $entity->id ? null : $label;
    }
}
