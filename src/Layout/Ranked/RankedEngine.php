<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Ranked;

use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/**
 * Ranked boxes in compound scopes: each cluster is laid out bottom-up as one opaque box of
 * its parent, then expanded top-down, so nested frames never overlap their children.
 */
final class RankedEngine implements LayoutEngine
{
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        /** @var array<array-key, LayoutNode> $nodes */
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }
        /** @var array<array-key, LayoutCluster> $groups */
        $groups = [];
        foreach ($graph->clusters as $cluster) {
            $groups[$cluster->id] = $cluster;
        }
        $projection = $this->project($graph, $nodes, $groups);
        yield from $projection;
        [$crossing, $scopedEdges] = $projection->getReturn();
        $directions = ['' => $graph->direction];
        foreach ($groups as $id => $group) {
            $id = (string) $id;
            $inherited = $directions[$group->parent ?? ''];
            $directions[$id] = match (true) {
                $graph->inheritAcrossLinks && \array_key_exists($id, $crossing) => $inherited,
                null !== $group->direction => $group->direction,
                default => $graph->undeclared ?? $inherited,
            };
            yield null;
        }
        $orders = [];
        foreach ($groups as $id => $group) {
            $orders[$id] = $group->order;
        }
        foreach ($nodes as $id => $node) {
            $orders[$id] = $node->order;
        }
        $items = [];
        $nodeNumber = 0;
        foreach ($nodes as $id => $node) {
            $items[$node->cluster ?? ''][$id] = new GraphNode($nodeNumber++, (string) $id, $node->label, 0.0, 0.0, $node->width, $node->height, null, null, dashed: false);
            yield null;
        }
        $sizes = [];
        $scopes = [];
        $groupNumber = -1;
        foreach ([...\array_reverse(\array_keys($groups)), ''] as $scope) {
            $scope = (string) $scope;
            $scopeItems = $items[$scope] ?? [];
            \uasort($scopeItems, static fn (GraphNode $a, GraphNode $b): int => $orders[$a->name] <=> $orders[$b->name]);
            foreach ($scopeItems as $id => $item) {
                $sizes[$id] = [$item->w, $item->h];
                $owner = $nodes[$id] ?? $groups[$id];
                [$px, $py] = [$owner->marginX, $owner->marginY];
                $scopeItems[$id] = new GraphNode($item->id, $item->name, $item->label, x: 0.0, y: 0.0, w: $item->w + (2 * $px), h: $item->h + (2 * $py), color: null, fillColor: null, dashed: false);
                yield null;
            }
            $work = new RankedBoxes()->place($scopeItems, $scopedEdges[$scope] ?? [], $directions[$scope]->value, $graph->spacing->rankGap, $graph->spacing->nodeGap, $graph->spacing->padding);
            yield from $work;
            $scopes[$scope] = $work->getReturn();
            if ('' !== $scope) {
                $group = $groups[$scope];
                $width = \max($scopes[$scope]->width, $group->minWidth);
                $height = $scopes[$scope]->height + $group->labelHeight + $graph->titleGap;
                $items[$group->parent ?? ''][$scope] = new GraphNode($groupNumber--, $scope, $group->label, 0.0, 0.0, $width, $height, null, null, dashed: false);
            }
            yield null;
        }
        $work = $this->expand($graph, $groups, $scopes, $directions, $sizes);
        yield from $work;

        return $work->getReturn();
    }

    /**
     * @param array<array-key, LayoutNode> $nodes
     * @param array<array-key, LayoutCluster> $groups
     * @return \Generator<int, null, mixed, array{array<array-key, true>, array<array-key, list<array{from: string, to: string, length: int, index: int}>>}>
     */
    private function project(LayoutGraph $graph, array $nodes, array $groups): \Generator
    {
        /** @var array<array-key, array<array-key, string>> $representatives */
        $representatives = [];
        foreach ([...\array_keys($nodes), ...\array_keys($groups)] as $id) {
            $nodeParent = \array_key_exists($id, $nodes) ? $nodes[$id]->cluster : null;
            $parent = \array_key_exists($id, $groups) ? $groups[$id]->parent : $nodeParent;
            $child = (string) $id;
            do {
                $representatives[$id][$parent ?? ''] = $child;
                $child = $parent;
                $parent = null === $parent ? null : $groups[$parent]->parent;
                yield null;
            } while (null !== $child);
        }
        $crossing = [];
        $scopedEdges = [];
        foreach ($graph->edges as $edge) {
            foreach ($representatives[$edge->from] as $scope => $from) {
                $to = $representatives[$edge->to][$scope] ?? null;
                if (null !== $to && $from !== $to) {
                    $scopedEdges[$scope][] = ['from' => $from, 'to' => $to, 'length' => $edge->minLength, 'index' => $edge->index];
                }
                yield null;
            }
            foreach ([[$edge->from, $edge->to], [$edge->to, $edge->from]] as [$inside, $outside]) {
                if (\array_key_exists($inside, $nodes)) {
                    foreach ($representatives[$inside] as $scope => $representative) {
                        if ('' !== $scope && $outside !== (string) $scope && !\array_key_exists($scope, $representatives[$outside])) {
                            $crossing[$scope] = true;
                        }
                        yield null;
                    }
                }
                yield null;
            }
        }

        return [$crossing, $scopedEdges];
    }

    /**
     * @param array<array-key, LayoutCluster> $groups
     * @param array<array-key, GraphLayout> $scopes
     * @param array<array-key, Direction> $directions
     * @param array<array-key, array{float, float}> $sizes
     * @return \Generator<int, null, mixed, Placement>
     */
    private function expand(LayoutGraph $graph, array $groups, array $scopes, array $directions, array $sizes): \Generator
    {
        $pending = [['', 0.0, 0.0]];
        $nodes = [];
        $clusters = [];
        $endpoints = [];
        $lanes = [];
        while ([] !== $pending) {
            [$scope, $dx, $dy] = \array_pop($pending);
            foreach ($scopes[$scope]->nodes as $node) {
                if (\str_starts_with($node->name, RankedBoxes::LANE)) {
                    $index = (int) \substr($node->name, \strlen(RankedBoxes::LANE));
                    $horizontal = \in_array($directions[$scope], [Direction::LeftRight, Direction::RightLeft], strict: true);
                    $sign = \in_array($directions[$scope], [Direction::BottomTop, Direction::RightLeft], strict: true) ? -1 : 1;
                    [$hx, $hy] = $horizontal ? [($sign * $node->w) / 2, 0.0] : [0.0, ($sign * $node->h) / 2];
                    $lanes[$index][] = [$node->x + $dx - $hx, $node->y + $dy - $hy];
                    $lanes[$index][] = [$node->x + $dx + $hx, $node->y + $dy + $hy];
                    yield null;
                    continue;
                }
                [$width, $height] = $sizes[$node->name];
                $placed = new GraphNode($node->id, $node->name, $node->label, $node->x + $dx, $node->y + $dy, $width, $height, null, null, dashed: false);
                $endpoints[$node->name] = $placed;
                if (\array_key_exists($node->name, $groups)) {
                    $left = $placed->x - ($placed->w / 2);
                    $top = $placed->y - ($placed->h / 2);
                    $clusters[] = new GraphCluster($placed->name, $placed->label, $left, $top, $left + $placed->w, $top + $placed->h, null, null);
                    $pending[] = [$node->name, $left, $top + $groups[$node->name]->labelHeight + $graph->titleGap];
                    yield null;
                    continue;
                }
                $nodes[$placed->id] = $placed;
                yield null;
            }
        }

        return new Placement(new GraphLayout($scopes['']->width, $scopes['']->height, $nodes, [], $clusters), $endpoints, $directions, $lanes);
    }
}
