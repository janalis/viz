<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;
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
 * `height`, the graph's `nodesep`, `ranksep` and `rankdir`, each edge's `minlen`, `weight` —
 * a hundred times more between two nodes of one `group`, as dot holds them straight — and
 * `constraint`, clusters with each node in the first one that claims it, and the `rank` of
 * every subgraph that sets one.
 */
final class DotLayoutInput
{
    public const float POINTS = 72.0;
    /** The longest an edge may ask to be, in ranks, and the most it may weigh: far past any drawing, short of exhausting memory. */
    public const int MAX_MINLEN = 100;
    public const int MAX_WEIGHT = 10_000;
    /** How much more an edge weighs between two nodes of one `group`. */
    public const int GROUP = 100;
    /** The largest size or separation taken, in inches. */
    public const float MAX_INCHES = 1000.0;

    /**
     * @param ?\Closure(DotNode): array{float, float} $measure the size a node's label needs, in points; null takes width and height alone
     * @param ?\Closure(DotEdge): array{float, float} $labels an edge label's size, in points
     * @param ?\Closure(DotSubgraph): array{float, float} $titles a cluster label's size, in points
     */
    public static function from(DotGraph $graph, ?\Closure $measure = null, ?\Closure $labels = null, ?\Closure $titles = null): LayoutGraph
    {
        $nodes = [];
        $clusterOf = [];
        $clusters = [];
        $claimed = [];
        self::clusters($graph, $graph->subgraphs, null, null, $claimed, $clusters, $clusterOf, $titles);
        $order = 0;
        foreach ($graph->nodes as $name => $node) {
            $width = self::inches($node->attribute('width'), 0.75);
            $height = self::inches($node->attribute('height'), 0.5);
            // A point takes no label: it is a dot as wide as the smaller of its sizes, 0.05in by default.
            if ('point' === $node->attribute('shape')) {
                $width = \min(self::inches($node->attribute('width'), 0.05), self::inches($node->attribute('height'), 0.05));
                $height = $width;
            }
            if (null !== $measure && 'true' !== \strtolower($node->attribute('fixedsize')) && 'point' !== $node->attribute('shape')) {
                [$labelWidth, $labelHeight] = $measure($node);
                $width = \max($width, $labelWidth);
                $height = \max($height, $labelHeight);
            }
            $ordering = \strtolower($node->attribute('ordering', $graph->attribute('ordering')));
            $nodes[] = new LayoutNode(
                $name,
                $node->attribute('label', '\N'),
                $width,
                $height,
                $clusterOf[$name] ?? null,
                $order++,
                ordering: \in_array($ordering, ['out', 'in'], strict: true) ? $ordering : null,
            );
        }
        $edges = [];
        foreach ($graph->edges as $index => $edge) {
            [$labelWidth, $labelHeight] = '' !== $edge->attribute('label') && null !== $labels ? $labels($edge) : [0.0, 0.0];
            $edges[] = new LayoutEdge(
                $index,
                $edge->tail,
                $edge->head,
                self::bounded($edge->attribute('minlen', '1'), self::MAX_MINLEN),
                self::bounded($edge->attribute('weight', '1'), self::MAX_WEIGHT) * (self::grouped($graph, $edge) ? self::GROUP : 1),
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
                \max(0.02 * self::POINTS, self::inches($graph->attribute('ranksep'), 0.5)),
                \max(0.02 * self::POINTS, self::inches($graph->attribute('nodesep'), 0.25)),
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
     * @param ?\Closure(DotSubgraph): array{float, float} $titles
     */
    private static function clusters(DotGraph $graph, array $subgraphs, ?string $parent, ?array $allowed, array &$claimed, array &$clusters, array &$clusterOf, ?\Closure $titles): void
    {
        foreach ($subgraphs as $subgraph) {
            if (!$subgraph->cluster()) {
                self::clusters($graph, $subgraph->subgraphs, $parent, $allowed, $claimed, $clusters, $clusterOf, $titles);
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
            [$titleWidth, $titleHeight] = null === $titles || '' === $subgraph->attribute('label') ? [0.0, 0.0] : $titles($subgraph);
            $clusters[] = new LayoutCluster($id, $subgraph->attribute('label'), $parent, minWidth: $titleWidth, labelHeight: $titleHeight, order: \count($clusters));
            $inner = [];
            self::clusters($graph, $subgraph->subgraphs, $id, $members, $inner, $clusters, $clusterOf, $titles);
        }
    }

    /** Whether an edge's ends share a `group`, which holds it straight. */
    private static function grouped(DotGraph $graph, DotEdge $edge): bool
    {
        $group = $graph->nodes[$edge->tail]->attribute('group');

        return '' !== $group && $graph->nodes[$edge->head]->attribute('group') === $group;
    }

    private static function inches(string $value, float $default): float
    {
        $inches = \is_numeric($value) ? (float) $value : $default;

        return \min(\max(\is_finite($inches) ? $inches : $default, 0.0), self::MAX_INCHES) * self::POINTS;
    }

    /** An integer attribute held between zero and a ceiling. */
    private static function bounded(string $value, int $ceiling): int
    {
        return \is_numeric($value) ? \min(\max(0, (int) \min((float) $value, $ceiling)), $ceiling) : 1;
    }
}
