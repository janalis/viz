<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * A Chen EER diagram as the shared graph model: entities boxes, relationships diamonds,
 * attributes and subclass circles ellipses, joined by lines without heads — bold ones thick —
 * a link's cardinality its label.
 */
final class ChenModel
{
    public static function of(PlantUmlChen $diagram): Graph
    {
        $ids = \array_flip(\array_keys($diagram->elements));
        $nodes = \array_map(static fn (ChenElement $element): Node => new Node(
            'n'.$ids[$element->id],
            \implode("\n", $element->display),
            match ($element->kind) {
                ChenKind::Entity => Shape::Box,
                ChenKind::Relationship => Shape::Diamond,
                ChenKind::Attribute, ChenKind::Circle => Shape::Ellipse,
            },
            $element->back,
            $element->line,
        ), \array_values($diagram->elements));
        $edges = [];
        foreach ($diagram->links as $link) {
            $edges[] = new Edge('n'.$ids[$link->from], 'n'.$ids[$link->to], $link->label ?? '', head: ArrowHead::None, style: $link->bold ? LineStyle::Bold : LineStyle::Solid);
        }
        $title = $diagram->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, [], $diagram->direction, null === $title ? '' : \implode("\n", $title->lines));
    }
}
