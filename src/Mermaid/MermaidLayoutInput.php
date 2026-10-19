<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Model\Direction;

/**
 * A Mermaid graph measured for a layout engine: node and cluster label sizes, the
 * room self-loops reserve, declaration order, and how subgraphs take a direction.
 */
final class MermaidLayoutInput
{
    /** @return \Generator<int, null, mixed, LayoutGraph> */
    public static function from(MermaidGraph $source): \Generator
    {
        $reservation = MermaidLoopSpace::measure($source);
        yield from $reservation;
        $padding = $reservation->getReturn();
        $nodes = [];
        foreach ($source->nodes as $id => $node) {
            $id = (string) $id;
            [$width, $height] = MermaidNodeMetrics::measure($source, $id);
            [$marginX, $marginY] = $padding[$id] ?? [0.0, 0.0];
            $owner = [] === $node['groups'] ? null : $node['groups'][\count($node['groups']) - 1];
            $nodes[] = new LayoutNode($id, $node['label'], $width, $height, $owner, $node['offset'], $marginX, $marginY);
            yield null;
        }
        $clusters = [];
        foreach ($source->groups as $id => $group) {
            $id = (string) $id;
            [$labelWidth, $labelHeight] = $source->styles->measureCluster($id, $group['label']);
            [$marginX, $marginY] = $padding[$id] ?? [0.0, 0.0];
            $clusters[] = new LayoutCluster(
                $id,
                $group['label'],
                $group['parent'],
                $group['explicit'] ? Direction::from($group['direction']) : null,
                $labelWidth + 4,
                $labelHeight,
                $group['offset'],
                $marginX,
                $marginY,
            );
            yield null;
        }
        $edges = [];
        foreach ($source->edges as $index => $edge) {
            $edges[] = new LayoutEdge($index, $edge['from'], $edge['to'], MermaidGraphEdges::rankSpan($edge['arrow']));
            yield null;
        }
        $preference = $source->flowchart?->inheritedDirection();

        return new LayoutGraph(
            $nodes,
            $edges,
            $clusters,
            Direction::from($source->direction),
            new Spacing($source->rankGap, $source->nodeGap, $source->padding),
            $source->inheritDirectionsAcrossLinks,
            match ($preference) {
                true => Direction::from($source->direction),
                false => Direction::TopBottom,
                null => null,
            },
            $source->flowchart?->titleGap() ?? 0.0,
        );
    }
}
