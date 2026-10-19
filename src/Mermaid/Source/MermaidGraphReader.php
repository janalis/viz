<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidFrontMatter;
use Janalis\Viz\Mermaid\MermaidGraph;
use Janalis\Viz\Mermaid\MermaidInitialization;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidSource;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Render\Work;

/**
 * A Mermaid flowchart read into the shared {@see Graph}: nodes, links, subgraphs,
 * direction and inline fill and stroke. Classes, link styles, clicks and the
 * shapes no other language draws fall back to the nearest common form.
 */
final class MermaidGraphReader
{
    private const array SHAPES = [
        'rounded' => Shape::Rounded,
        'stadium' => Shape::Stadium,
        'circle' => Shape::Circle,
        'doublecircle' => Shape::Circle,
        'ellipse' => Shape::Ellipse,
        'diamond' => Shape::Diamond,
        'hexagon' => Shape::Hexagon,
        'lean-r' => Shape::Parallelogram,
        'lean-l' => Shape::Parallelogram,
        'cylinder' => Shape::Cylinder,
        'doc' => Shape::Note,
    ];

    /**
     * @throws MermaidSyntaxError when the source is not a valid flowchart
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): Graph
    {
        $frontMatter = MermaidFrontMatter::parse(MermaidSource::unmarked($source));
        $initialization = Work::run(MermaidInitialization::parse($frontMatter->source, $frontMatter->configuration));
        $parser = new FlowchartParser($initialization->configuration->flowchart);
        foreach (new MermaidLexer($initialization->source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $graph = $parser->finish();

        return new Graph(
            self::nodes($graph),
            \array_map(self::edge(...), $graph->edges),
            self::clusters($graph, parent: null),
            Direction::from($graph->direction),
            $frontMatter->title->text ?? '',
        );
    }

    /** @return list<Node> */
    private static function nodes(MermaidGraph $graph): array
    {
        $nodes = [];
        foreach ($graph->nodes as $id => $node) {
            $id = (string) $id;
            $style = $graph->styles->forElement($id);
            $nodes[] = new Node(
                $id,
                $node['label'] === $id ? null : $node['label'],
                self::SHAPES[$graph->shapes[$id] ?? 'rect'] ?? Shape::Box,
                \is_string($style['fill'] ?? null) ? $style['fill'] : null,
                \is_string($style['stroke'] ?? null) ? $style['stroke'] : null,
            );
        }

        return $nodes;
    }

    /** @param array{from: string, to: string, label: string, arrow: string, origin: int} $edge */
    private static function edge(array $edge): Edge
    {
        $arrow = $edge['arrow'];

        return new Edge(
            $edge['from'],
            $edge['to'],
            $edge['label'],
            self::head($arrow[\strlen($arrow) - 1]),
            self::head($arrow[0]),
            match (true) {
                \str_contains($arrow, '=') => LineStyle::Bold,
                \str_contains($arrow, '.') => LineStyle::Dotted,
                default => LineStyle::Solid,
            },
        );
    }

    private static function head(string $end): ArrowHead
    {
        return match ($end) {
            '>', '<' => ArrowHead::Arrow,
            'o' => ArrowHead::Circle,
            'x' => ArrowHead::Cross,
            default => ArrowHead::None,
        };
    }

    /** @return list<Cluster> */
    private static function clusters(MermaidGraph $graph, ?string $parent): array
    {
        $clusters = [];
        foreach ($graph->groups as $id => $group) {
            if ($group['parent'] !== $parent) {
                continue;
            }
            $id = (string) $id;
            $members = \array_keys(\array_filter($graph->nodes, static fn (array $node): bool => \end($node['groups']) === $id));
            $clusters[] = new Cluster($id, $group['label'], \array_map(\strval(...), $members), self::clusters($graph, $id));
        }

        return $clusters;
    }
}
