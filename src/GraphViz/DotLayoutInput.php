<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\DotShape;
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
use Janalis\Viz\Model\Graph\Shape;

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
     * @param ?\Closure(DotNode, string, float, float): ?array{float, float} $ports where a port puts an edge's end on a node of a width and height, from its centre, in points; null where it names nothing
     */
    public static function from(DotGraph $graph, ?\Closure $measure = null, ?\Closure $labels = null, ?\Closure $titles = null, ?\Closure $ports = null): LayoutGraph
    {
        $sizes = [];
        $nodes = [];
        $clusterOf = [];
        $clusters = [];
        $claimed = [];
        self::clusters($graph, $graph->subgraphs, null, null, $claimed, $clusters, $clusterOf, $titles);
        $order = 0;
        foreach ($graph->nodes as $node) {
            // The node's own name: its key in the list is a number where the name is one.
            $name = $node->name;
            [$width, $height] = self::least($node);
            if (null !== $measure && 'true' !== \strtolower($node->attribute('fixedsize')) && 'point' !== $node->attribute('shape')) {
                [$labelWidth, $labelHeight] = $measure($node);
                $width = \max($width, $labelWidth);
                $height = \max($height, $labelHeight);
            }
            if (self::regular($node)) {
                $width = \max($width, $height);
                $height = $width;
            }
            [$width, $height] = DotNodes::ringed($node, $width, $height);
            $sizes[$name] = [$width, $height];
            $ordering = \strtolower($node->attribute('ordering', $graph->attribute('ordering')));
            $nodes[] = new LayoutNode(
                $name,
                $node->attribute('label', '\N'),
                $width,
                $height,
                $clusterOf[$name] ?? null,
                $order++,
                ordering: \in_array($ordering, ['out', 'in'], strict: true) ? $ordering : null,
                shape: self::outline($node->attribute('shape', 'ellipse')),
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
                self::port($graph, $ports, $sizes, $edge->tail, $edge->attribute('tailport')),
                self::port($graph, $ports, $sizes, $edge->head, $edge->attribute('headport')),
                self::inches($edge->attribute('len'), 1.0),
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
            aspect: self::aspect($graph),
            concentrate: \in_array(\strtolower($graph->attribute('concentrate')), ['true', 'yes', '1'], strict: true),
        );
    }

    /**
     * The height-to-width ratio `ratio` asks the drawing for: a number itself, or `fill` the
     * ratio of `size`; null for any other value, or none.
     */
    private static function aspect(DotGraph $graph): ?float
    {
        $ratio = \strtolower(\trim($graph->attribute('ratio')));
        if (\is_numeric($ratio)) {
            return \max(0.01, \min(100.0, (float) $ratio));
        }
        $size = \explode(',', \rtrim($graph->attribute('size'), characters: '!'));
        [$width, $height] = [\trim($size[0]), \trim($size[1] ?? $size[0])];
        if ('fill' !== $ratio || !\is_numeric($width) || !\is_numeric($height) || (float) $width <= 0) {
            return null;
        }

        return \max(0.01, \min(100.0, (float) $height / (float) $width));
    }

    /**
     * Where across a node a port puts an edge's end, along its rank's order as the ordering
     * runs: across it laid out down, up it laid out sideways, as a part of its breadth from its centre.
     *
     * @param ?\Closure(DotNode, string, float, float): ?array{float, float} $ports
     * @param array<string, array{float, float}> $sizes
     */
    private static function port(DotGraph $graph, ?\Closure $ports, array $sizes, string $name, string $port): float
    {
        if (null === $ports || '' === $port) {
            return 0.0;
        }
        [$width, $height] = $sizes[$name];
        $at = $ports($graph->nodes[$name], $port, $width, $height);
        if (null === $at) {
            return 0.0;
        }
        // Laid out sideways, ranks are ordered from their far end, as dot orders them, then turned over.
        $across = \in_array(\strtoupper($graph->attribute('rankdir')), ['LR', 'RL'], strict: true) ? -$at[1] / $height : $at[0] / $width;

        return \max(-0.5, \min(0.5, $across));
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

    /**
     * The least size a node takes, in points, as `dot` is seen to size it: its width and height,
     * 0.75in by 0.5in by default; a round or regular node as wide as tall, the larger of those
     * written, 0.5in when none is; a point the smaller of those written, 0.05in when none is.
     *
     * @return array{float, float}
     */
    public static function least(DotNode $node): array
    {
        $written = \array_values(\array_filter([$node->attribute('width'), $node->attribute('height')], static fn (string $size): bool => \is_numeric(\trim($size))));
        $sizes = \array_map(static fn (string $size): float => self::inches($size, 0.5), $written);
        if ('point' === $node->attribute('shape')) {
            $side = [] === $sizes ? 0.05 * self::POINTS : \min($sizes);

            return [$side, $side];
        }
        if (self::regular($node)) {
            $side = [] === $sizes ? 0.5 * self::POINTS : \max($sizes);

            return [$side, $side];
        }

        return [self::inches($node->attribute('width'), 0.75), self::inches($node->attribute('height'), 0.5)];
    }

    /** Whether a node is as wide as tall: round, or `regular`. */
    private static function regular(DotNode $node): bool
    {
        return DotShape::round($node->attribute('shape', 'ellipse')) || 'true' === \strtolower($node->attribute('regular'));
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

    /** The outline a node's edges stop at, an ellipse by default, as `dot` draws a node. */
    private static function outline(string $shape): Shape
    {
        return match (\strtolower($shape)) {
            '', 'ellipse', 'oval', 'circle', 'doublecircle', 'mcircle', 'point' => Shape::Ellipse,
            'diamond', 'mdiamond' => Shape::Diamond,
            default => Shape::Box,
        };
    }
}
