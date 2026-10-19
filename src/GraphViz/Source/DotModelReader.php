<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Source;

use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * A parsed {@see DotGraph} read into the shared {@see Graph}, mapping names the way
 * {@see DotGraphReader} maps dot's own JSON: shapes, arrowheads and line styles with no
 * shared equivalent become a box, an arrow and a solid line. A subgraph that is not a
 * cluster lends its nodes and clusters to its parent; a node belongs to its innermost
 * cluster.
 */
final class DotModelReader
{
    public static function read(DotGraph $graph): Graph
    {
        $dir = $graph->directed ? 'forward' : 'none';

        return new Graph(
            \array_values(\array_map(self::node(...), $graph->nodes)),
            \array_map(static fn (DotEdge $edge): Edge => self::edge($edge, $dir), $graph->edges),
            self::clusters($graph->subgraphs, $graph),
            Direction::tryFrom(\strtoupper($graph->attribute('rankdir'))) ?? Direction::TopBottom,
            \str_replace(['\G', '\n', '\l', '\r'], [$graph->name, "\n", "\n", "\n"], $graph->attribute('label')),
        );
    }

    private static function node(DotNode $node): Node
    {
        $label = $node->attribute('label', '\N');
        $label = '' === $label || '\N' === $label ? null : \str_replace(['\N', '\n', '\l', '\r'], [$node->name, "\n", "\n", "\n"], $label);
        $styles = \array_map(\trim(...), \explode(',', $node->attribute('style')));
        $fill = $node->attribute('fillcolor');

        return new Node(
            $node->name,
            $label === $node->name ? null : $label,
            DotGraphReader::SHAPES[$node->attribute('shape')] ?? (\in_array('rounded', $styles, strict: true) ? Shape::Rounded : Shape::Box),
            '' !== $fill && \in_array('filled', $styles, strict: true) ? $fill : null,
            '' !== $node->attribute('color') ? $node->attribute('color') : null,
        );
    }

    private static function edge(DotEdge $edge, string $defaultDir): Edge
    {
        $dir = $edge->attribute('dir', $defaultDir);

        return new Edge(
            $edge->tail,
            $edge->head,
            \str_replace(['\n', '\l', '\r'], replace: "\n", subject: $edge->attribute('label')),
            \in_array($dir, ['forward', 'both'], strict: true) ? DotGraphReader::arrow($edge->attribute('arrowhead')) : \Janalis\Viz\Model\Graph\ArrowHead::None,
            \in_array($dir, ['back', 'both'], strict: true) ? DotGraphReader::arrow($edge->attribute('arrowtail')) : \Janalis\Viz\Model\Graph\ArrowHead::None,
            match (\trim(\explode(',', $edge->attribute('style'))[0])) {
                'dashed' => LineStyle::Dashed,
                'dotted' => LineStyle::Dotted,
                'bold' => LineStyle::Bold,
                default => LineStyle::Solid,
            },
        );
    }

    /**
     * The clusters among these subgraphs, a plain subgraph lending its own. A node belongs to
     * the first cluster that claims it, as dot draws it, and to its innermost cluster there.
     *
     * @param list<DotSubgraph> $subgraphs
     * @param ?array<array-key, true> $allowed the nodes these clusters may hold; null for any
     * @param array<array-key, true> $claimed the nodes earlier clusters hold
     * @return list<Cluster>
     */
    private static function clusters(array $subgraphs, DotGraph $graph, ?array $allowed = null, array &$claimed = []): array
    {
        $clusters = [];
        foreach ($subgraphs as $subgraph) {
            if (!$subgraph->cluster()) {
                \array_push($clusters, ...self::clusters($subgraph->subgraphs, $graph, $allowed, $claimed));
                continue;
            }
            $members = [];
            foreach ($graph->ordered($subgraph->nodes) as $node) {
                if ((null !== $allowed && !\array_key_exists($node, $allowed)) || \array_key_exists($node, $claimed)) {
                    continue;
                }
                $members[$node] = true;
            }
            $claimed += $members;
            $inner = [];
            $children = self::clusters($subgraph->subgraphs, $graph, $members, $inner);
            $clusters[] = new Cluster(
                $subgraph->name,
                \str_replace(['\G', '\n', '\l', '\r'], [$subgraph->name, "\n", "\n", "\n"], $subgraph->attribute('label')),
                \array_values(\array_filter(\array_map(\strval(...), \array_keys($members)), static fn (string $node): bool => !\array_key_exists($node, $inner))),
                $children,
            );
        }

        return $clusters;
    }
}
