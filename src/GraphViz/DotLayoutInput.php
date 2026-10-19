<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\LayoutRankSet;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Model\Direction;

/**
 * A parsed DOT graph as a layout engine's input, in points: node sizes from `width` and
 * `height`, the graph's `nodesep`, `ranksep` and `rankdir`, each edge's `minlen`, `weight`
 * and `constraint`, clusters with each node in the first one that claims it, and the
 * `rank` of every subgraph that sets one.
 */
final class DotLayoutInput
{
    public const float POINTS = 72.0;

    /**
     * @param ?\Closure(string, string): array{float, float} $measure a node's size from its name and label, in points; null takes width and height alone
     * @param ?\Closure(string): array{float, float} $labels an edge label's size, in points
     */
    public static function from(DotGraph $graph, ?\Closure $measure = null, ?\Closure $labels = null): LayoutGraph
    {
        $nodes = [];
        $clusterOf = [];
        $clusters = [];
        $claimed = [];
        self::clusters($graph, $graph->subgraphs, null, null, $claimed, $clusters, $clusterOf);
        $order = 0;
        foreach ($graph->nodes as $name => $node) {
            $width = self::inches($node->attribute('width'), 0.75);
            $height = self::inches($node->attribute('height'), 0.5);
            if (null !== $measure && 'true' !== \strtolower($node->attribute('fixedsize'))) {
                [$labelWidth, $labelHeight] = $measure($name, $node->attribute('label', '\N'));
                $width = \max($width, $labelWidth);
                $height = \max($height, $labelHeight);
            }
            $nodes[] = new LayoutNode($name, $node->attribute('label', '\N'), $width, $height, $clusterOf[$name] ?? null, $order++);
        }
        $edges = [];
        foreach ($graph->edges as $index => $edge) {
            $label = $edge->attribute('label');
            [$labelWidth, $labelHeight] = '' !== $label && null !== $labels ? $labels($label) : [0.0, 0.0];
            $edges[] = new LayoutEdge(
                $index,
                $edge->tail,
                $edge->head,
                \max(0, (int) $edge->attribute('minlen', '1')),
                \max(0, (int) $edge->attribute('weight', '1')),
                'false' !== \strtolower($edge->attribute('constraint', 'true')),
                $labelWidth,
                $labelHeight,
            );
        }
        $sets = [];
        foreach ($graph->allSubgraphs() as $subgraph) {
            $kind = RankKind::tryFrom(\strtolower($subgraph->attribute('rank')));
            if (null === $kind || [] === $subgraph->nodes) {
                continue;
            }
            $sets[] = new LayoutRankSet($kind, $graph->ordered($subgraph->nodes));
        }

        return new LayoutGraph(
            $nodes,
            $edges,
            $clusters,
            Direction::tryFrom(\strtoupper($graph->attribute('rankdir'))) ?? Direction::TopBottom,
            new Spacing(
                \max(0.02, self::inches($graph->attribute('ranksep'), 0.5)),
                \max(0.02, self::inches($graph->attribute('nodesep'), 0.25)),
                4.0,
            ),
            rankSets: $sets,
        );
    }

    /**
     * Clusters in order, each node in the first that claims it and there in its innermost.
     *
     * @param list<DotSubgraph> $subgraphs
     * @param ?array<string, true> $allowed the nodes these clusters may hold; null for any
     * @param array<string, true> $claimed the nodes earlier clusters at this level hold
     * @param list<LayoutCluster> $clusters
     * @param array<string, string> $clusterOf
     */
    private static function clusters(DotGraph $graph, array $subgraphs, ?string $parent, ?array $allowed, array &$claimed, array &$clusters, array &$clusterOf): void
    {
        foreach ($subgraphs as $subgraph) {
            if (!$subgraph->cluster()) {
                self::clusters($graph, $subgraph->subgraphs, $parent, $allowed, $claimed, $clusters, $clusterOf);
                continue;
            }
            // dot warns of a cluster name used twice and draws both: each gets an id of its own.
            $id = $subgraph->name;
            $taken = \array_map(static fn (LayoutCluster $cluster): string => $cluster->id, $clusters);
            for ($copy = 1; \in_array($id, $taken, strict: true); ++$copy) {
                $id = $subgraph->name.'#'.$copy;
            }
            $members = [];
            foreach ($graph->ordered($subgraph->nodes) as $node) {
                if ((null !== $allowed && !\array_key_exists($node, $allowed)) || \array_key_exists($node, $claimed)) {
                    continue;
                }
                $members[$node] = true;
                $clusterOf[$node] = $id;
            }
            $claimed += $members;
            $clusters[] = new LayoutCluster($id, $subgraph->attribute('label'), $parent, order: \count($clusters));
            $inner = [];
            self::clusters($graph, $subgraph->subgraphs, $id, $members, $inner, $clusters, $clusterOf);
        }
    }

    private static function inches(string $value, float $default): float
    {
        return (\is_numeric($value) ? (float) $value : $default) * self::POINTS;
    }
}
