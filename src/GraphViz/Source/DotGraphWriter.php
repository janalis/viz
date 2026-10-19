<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Source;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * A {@see Graph} as Graphviz DOT. Every identifier and label is quoted, so any
 * text is safe. dot has no stadium, so it is drawn as a rounded box, and no
 * cross arrowhead, so it is drawn as a tee. Deterministic: the same graph always
 * writes the same bytes.
 */
final class DotGraphWriter
{
    public static function write(Graph $graph, string $name = 'G'): string
    {
        $lines = ['digraph '.self::quote($name).' {', '    rankdir='.$graph->direction->value.';', '    node [shape=box];'];
        if ('' !== $graph->title) {
            $lines[] = '    label='.self::quote($graph->title).';';
            $lines[] = '    labelloc=t;';
        }
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }
        $index = 0;
        foreach ($graph->clusters as $cluster) {
            \array_push($lines, ...self::cluster($cluster, $nodes, $index, '    '));
        }
        foreach ($graph->looseNodes() as $node) {
            $lines[] = '    '.self::node($node);
        }
        foreach ($graph->edges as $edge) {
            $lines[] = '    '.self::edge($edge);
        }
        $lines[] = '}';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Node> $nodes
     * @return list<string>
     */
    private static function cluster(Cluster $cluster, array $nodes, int &$index, string $indent): array
    {
        $lines = [$indent.'subgraph cluster_'.$index.' {', $indent.'    label='.self::quote($cluster->label).';'];
        ++$index;
        foreach ($cluster->nodes as $id) {
            $lines[] = $indent.'    '.(\array_key_exists($id, $nodes) ? self::node($nodes[$id]) : self::quote($id).';');
        }
        foreach ($cluster->clusters as $inner) {
            \array_push($lines, ...self::cluster($inner, $nodes, $index, $indent.'    '));
        }
        $lines[] = $indent.'}';

        return $lines;
    }

    private static function node(Node $node): string
    {
        $attributes = ['label='.self::quote($node->text())];
        $shape = match ($node->shape) {
            Shape::Box, Shape::Rounded, Shape::Stadium => null,
            Shape::Circle => 'circle',
            Shape::Ellipse => 'ellipse',
            Shape::Diamond => 'diamond',
            Shape::Hexagon => 'hexagon',
            Shape::Parallelogram => 'parallelogram',
            Shape::Cylinder => 'cylinder',
            Shape::Note => 'note',
        };
        if (null !== $shape) {
            $attributes[] = 'shape='.$shape;
        }
        $style = \array_filter([
            \in_array($node->shape, [Shape::Rounded, Shape::Stadium], strict: true) ? 'rounded' : '',
            null !== $node->fill ? 'filled' : '',
        ]);
        if ([] !== $style) {
            $attributes[] = 'style='.self::quote(\implode(',', $style));
        }
        if (null !== $node->fill) {
            $attributes[] = 'fillcolor='.self::quote($node->fill);
        }
        if (null !== $node->stroke) {
            $attributes[] = 'color='.self::quote($node->stroke);
        }

        return self::quote($node->id).' ['.\implode(', ', $attributes).'];';
    }

    private static function edge(Edge $edge): string
    {
        $attributes = [];
        if ('' !== $edge->label) {
            $attributes[] = 'label='.self::quote($edge->label);
        }
        if (LineStyle::Solid !== $edge->style) {
            $attributes[] = 'style='.$edge->style->value;
        }
        if (ArrowHead::Arrow !== $edge->head) {
            $attributes[] = 'arrowhead='.self::arrow($edge->head);
        }
        if (ArrowHead::None !== $edge->tail) {
            $attributes[] = 'arrowtail='.self::arrow($edge->tail);
            $attributes[] = 'dir=both';
        }

        return self::quote($edge->from).' -> '.self::quote($edge->to).([] === $attributes ? '' : ' ['.\implode(', ', $attributes).']').';';
    }

    private static function arrow(ArrowHead $head): string
    {
        return match ($head) {
            ArrowHead::None => 'none',
            ArrowHead::Arrow => 'normal',
            ArrowHead::Circle => 'dot',
            ArrowHead::Cross => 'tee',
        };
    }

    private static function quote(string $value): string
    {
        return '"'.\str_replace(['\\', '"', "\r\n", "\n"], ['\\\\', '\\"', '\\n', '\\n'], $value).'"';
    }
}
