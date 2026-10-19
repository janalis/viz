<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * A {@see Graph} as a PlantUML deployment diagram, the kind that draws free-form
 * elements and arrows. Every element gets a generated alias, so no identifier
 * needs escaping, and labels are quoted with `"` written as `&#34;`.
 *
 * PlantUML lays out top-down or left-to-right only, so BT and RL fall back to
 * those. It has no diamond or parallelogram element (drawn as rectangles), no
 * stadium (a rounded card), and no cross arrowhead (a `+`).
 */
final class PlantUmlGraphWriter
{
    public static function write(Graph $graph): string
    {
        $aliases = [];
        foreach ($graph->nodes as $node) {
            $aliases[$node->id] = 'n'.\count($aliases);
        }
        // An edge may name a node never declared; PlantUML needs every element declared.
        $implicit = [];
        foreach ($graph->edges as $edge) {
            foreach ([$edge->from, $edge->to] as $id) {
                if (\array_key_exists($id, $aliases)) {
                    continue;
                }
                $aliases[$id] = 'n'.\count($aliases);
                $implicit[] = new Node($id);
            }
        }
        $lines = ['@startuml'];
        if (\in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true)) {
            $lines[] = 'left to right direction';
        }
        if ('' !== $graph->title) {
            $lines[] = 'title '.self::text($graph->title);
        }
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }
        $index = 0;
        foreach ($graph->clusters as $cluster) {
            \array_push($lines, ...self::cluster($cluster, $nodes, $aliases, $index, ''));
        }
        foreach ([...$graph->looseNodes(), ...$implicit] as $node) {
            $lines[] = self::node($node, $aliases[$node->id]);
        }
        foreach ($graph->edges as $edge) {
            $lines[] = self::edge($edge, $aliases);
        }
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Node>   $nodes
     * @param array<string, string> $aliases
     * @return list<string>
     */
    private static function cluster(Cluster $cluster, array $nodes, array $aliases, int &$index, string $indent): array
    {
        $lines = [$indent.'rectangle '.self::quote($cluster->label).' as c'.$index.' {'];
        ++$index;
        foreach ($cluster->nodes as $id) {
            // A member that is no node has nothing to draw: PlantUML would need it declared.
            if (!\array_key_exists($id, $nodes)) {
                continue;
            }
            $lines[] = $indent.'  '.self::node($nodes[$id], $aliases[$id]);
        }
        foreach ($cluster->clusters as $inner) {
            \array_push($lines, ...self::cluster($inner, $nodes, $aliases, $index, $indent.'  '));
        }
        $lines[] = $indent.'}';

        return $lines;
    }

    private static function node(Node $node, string $alias): string
    {
        $element = match ($node->shape) {
            Shape::Box, Shape::Diamond, Shape::Parallelogram => 'rectangle',
            Shape::Rounded, Shape::Stadium => 'card',
            Shape::Circle => 'circle',
            Shape::Ellipse => 'usecase',
            Shape::Hexagon => 'hexagon',
            Shape::Cylinder => 'database',
            Shape::Note => 'file',
            Shape::Actor => 'actor',
        };
        $color = '';
        if (null !== $node->fill || null !== $node->stroke) {
            $color = ' #'.\ltrim($node->fill ?? 'white', characters: '#').(null === $node->stroke ? '' : ';line:'.\ltrim($node->stroke, characters: '#'));
        }

        return $element.' '.self::quote($node->lines('----')).' as '.$alias.$color;
    }

    /** @param array<string, string> $aliases */
    private static function edge(Edge $edge, array $aliases): string
    {
        $style = match ($edge->style) {
            LineStyle::Solid => '',
            LineStyle::Dashed => '[dashed]',
            LineStyle::Dotted => '[dotted]',
            LineStyle::Bold => '[bold]',
        };
        $head = match ($edge->head) {
            ArrowHead::Arrow => '>',
            ArrowHead::Circle, ArrowHead::HollowDiamond => 'o',
            ArrowHead::Cross => '+',
            ArrowHead::Triangle => '|>',
            ArrowHead::Diamond => '*',
            ArrowHead::None => '',
        };
        $tail = match ($edge->tail) {
            ArrowHead::Arrow => '<',
            ArrowHead::Circle, ArrowHead::HollowDiamond => 'o',
            ArrowHead::Cross => '+',
            ArrowHead::Triangle => '<|',
            ArrowHead::Diamond => '*',
            ArrowHead::None => '',
        };
        $label = '' === $edge->label ? '' : ' : '.self::text($edge->label);

        return $aliases[$edge->from].' '.$tail.'-'.$style.'-'.$head.' '.$aliases[$edge->to].$label;
    }

    /** Text in quotes; an empty one as a space, since PlantUML refuses empty quotes there. */
    private static function quote(string $text): string
    {
        return '"'.('' === $text ? ' ' : self::text($text)).'"';
    }

    /** One line of text: quotes as entities, line breaks as PlantUML's `\n`. */
    private static function text(string $text): string
    {
        return \str_replace(['"', "\r\n", "\n"], ['&#34;', '\n', '\n'], $text);
    }
}
