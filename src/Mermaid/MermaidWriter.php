<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Diagram\ArrowHead;
use Janalis\Viz\Diagram\Cluster;
use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\Graph;
use Janalis\Viz\Diagram\LineStyle;
use Janalis\Viz\Diagram\Node;
use Janalis\Viz\Diagram\Shape;

/**
 * A {@see Graph} as a Mermaid flowchart. An identifier Mermaid would misread (a
 * keyword, a space, punctuation) is replaced by a generated one; labels are
 * always quoted, with `"` as `#quot;` and line breaks as `<br>`. Mermaid has no
 * plain ellipse, so it is drawn as a circle; a dashed line is drawn dotted.
 */
final class MermaidWriter
{
    private const array RESERVED = ['end', 'graph', 'flowchart', 'subgraph', 'style', 'classdef', 'class', 'click', 'linkstyle', 'direction', 'default', 'call', 'href'];

    public static function write(Graph $graph): string
    {
        $ids = self::identifiers($graph);
        $lines = [];
        if ('' !== $graph->title) {
            \array_push($lines, '---', 'title: '.\json_encode($graph->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        $lines[] = 'flowchart '.$graph->direction->value;
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }
        $index = 0;
        foreach ($graph->clusters as $cluster) {
            \array_push($lines, ...self::cluster($cluster, $nodes, $ids, $index, '    '));
        }
        foreach ($graph->looseNodes() as $node) {
            $lines[] = '    '.self::node($node, $ids);
        }
        foreach ($graph->edges as $edge) {
            $lines[] = '    '.self::edge($edge, $ids);
        }
        foreach ($graph->nodes as $node) {
            $style = \array_filter(['fill' => $node->fill, 'stroke' => $node->stroke], static fn (?string $value): bool => null !== $value);
            if ([] !== $style) {
                $lines[] = '    style '.$ids[$node->id].' '.\implode(',', \array_map(static fn (string $key, string $value): string => $key.':'.$value, \array_keys($style), $style));
            }
        }

        return \implode("\n", $lines)."\n";
    }

    /**
     * Each node keeps its identifier when Mermaid reads it back the same; otherwise it gets `n<index>`.
     *
     * @return array<string, string>
     */
    private static function identifiers(Graph $graph): array
    {
        $all = \array_map(static fn (Node $node): string => $node->id, $graph->nodes);
        foreach ($graph->edges as $edge) {
            \array_push($all, $edge->from, $edge->to);
        }
        $members = static function (Cluster $cluster) use (&$members, &$all): void {
            \array_push($all, ...$cluster->nodes);
            \array_map($members, $cluster->clusters);
        };
        \array_map($members, $graph->clusters);
        $ids = [];
        foreach ($all as $id) {
            if (1 !== \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id) || \in_array(\strtolower($id), self::RESERVED, strict: true)) {
                continue;
            }
            $ids[$id] = $id;
        }
        // Generated identifiers come second, so they never take one an author chose.
        $taken = $ids;
        $next = 0;
        foreach ($all as $id) {
            if (\array_key_exists($id, $ids)) {
                continue;
            }
            while (\array_key_exists('n'.$next, $taken)) {
                ++$next;
            }
            $ids[$id] = 'n'.$next;
            $taken['n'.$next] = true;
        }

        return $ids;
    }

    /**
     * @param array<string, Node>   $nodes
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function cluster(Cluster $cluster, array $nodes, array $ids, int &$index, string $indent): array
    {
        $lines = [$indent.'subgraph c'.$index.' ['.self::quote('' !== $cluster->label ? $cluster->label : ' ').']'];
        ++$index;
        foreach ($cluster->nodes as $id) {
            $lines[] = $indent.'    '.(\array_key_exists($id, $nodes) ? self::node($nodes[$id], $ids) : $ids[$id]);
        }
        foreach ($cluster->clusters as $inner) {
            \array_push($lines, ...self::cluster($inner, $nodes, $ids, $index, $indent.'    '));
        }
        $lines[] = $indent.'end';

        return $lines;
    }

    /** @param array<string, string> $ids */
    private static function node(Node $node, array $ids): string
    {
        $label = self::quote($node->text());

        return (
            $ids[$node->id].match ($node->shape) {
                Shape::Box => '['.$label.']',
                Shape::Rounded => '('.$label.')',
                Shape::Stadium => '(['.$label.'])',
                Shape::Circle, Shape::Ellipse => '(('.$label.'))',
                Shape::Diamond => '{'.$label.'}',
                Shape::Hexagon => '{{'.$label.'}}',
                Shape::Parallelogram => '[/'.$label.'/]',
                Shape::Cylinder => '[('.$label.')]',
                Shape::Note => '@{ shape: doc, label: '.$label.' }',
            }
        );
    }

    /** @param array<string, string> $ids */
    private static function edge(Edge $edge, array $ids): string
    {
        $head = match ($edge->head) {
            ArrowHead::Arrow => '>',
            ArrowHead::Circle => 'o',
            ArrowHead::Cross => 'x',
            ArrowHead::None => '',
        };
        $tail = match ($edge->tail) {
            ArrowHead::Arrow => '<',
            ArrowHead::Circle => 'o',
            ArrowHead::Cross => 'x',
            ArrowHead::None => '',
        };
        $link = match ($edge->style) {
            LineStyle::Solid => $tail.'--'.('' === $head ? '-' : $head),
            LineStyle::Bold => $tail.'=='.('' === $head ? '=' : $head),
            LineStyle::Dashed, LineStyle::Dotted => $tail.'-.-'.$head,
        };
        $label = '' === $edge->label ? '' : '|'.self::quote($edge->label).'|';

        return $ids[$edge->from].' '.$link.$label.' '.$ids[$edge->to];
    }

    private static function quote(string $text): string
    {
        return '"'.\str_replace(['"', "\r\n", "\n"], ['#quot;', '<br>', '<br>'], $text).'"';
    }
}
